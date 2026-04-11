<?php

namespace App\Http\Controllers;

use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\ReceiptModel;
use App\Services\DueCalculationService;
use Illuminate\Support\Facades\DB;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;

class PaymentFollowupPdfController extends Controller
{
    /** Max table rows per WriteHTML chunk (establishment + partner lines). */
    private const PDF_MAX_EST_TABLE_ROWS = 300;

    /** Max untagged family rows per WriteHTML chunk. */
    private const PDF_UNTAGGED_CHUNK = 80;
    /**
     * GET /establishment/payment-followup-pdf
     * Bulk-loaded data only (avoids N+1 / gateway timeouts).
     */
    public function establishmentWisePdf()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $dueService = app(DueCalculationService::class);
            $currentYear = $dueService->getCurrentYear();
            $yearsList = $this->distinctSabeelYearsSorted();

            $establishments = EstablishmentModel::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            if ($establishments->isEmpty()) {
                return $this->emptyPdfResponse();
            }

            $estIds = $establishments->pluck('establishment_id')->unique()->values()->all();

            $estDueBulk = $dueService->getEstablishmentDueBulk($estIds, $currentYear);
            $estSabeelByYear = $this->nestEstSabeelByYear(
                EstablishmentSabeelModel::query()
                    ->whereIn('establishment_id', $estIds)
                    ->get(['establishment_id', 'year', 'sabeel'])
            );
            $estPaidByYear = $this->nestPaidByYear(
                DB::table('t_receipts')
                    ->whereIn('establishment_id', $estIds)
                    ->where('status', 'active')
                    ->select('establishment_id', 'year', DB::raw('SUM(amount) as paid'))
                    ->groupBy('establishment_id', 'year')
                    ->get()
            );

            $lastReceiptByEst = $this->lastReceiptIdsKeyed('establishment_id', $estIds);

            $linksByEst = MumineenEstablishmentModel::query()
                ->whereIn('establishment_id', $estIds)
                ->get()
                ->groupBy('establishment_id');

            $partnerFamilyIds = $linksByEst->flatten()->pluck('family_id')->unique()->values()->all();

            $linkedFamilyIds = MumineenEstablishmentModel::query()
                ->distinct()
                ->pluck('family_id');

            $untaggedHofs = MumineenModel::query()
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->when($linkedFamilyIds->isNotEmpty(), fn ($q) => $q->whereNotIn('family_id', $linkedFamilyIds->all()))
                ->orderBy('name')
                ->get();

            $untaggedFamilyIds = $untaggedHofs->pluck('family_id')->all();
            $allFamilyIds = array_values(array_unique(array_merge($partnerFamilyIds, $untaggedFamilyIds)));

            $hofsByFamily = MumineenModel::query()
                ->whereIn('family_id', $allFamilyIds)
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->get()
                ->keyBy('family_id');

            $famDueBulk = $allFamilyIds === [] ? [] : $dueService->getFamilyDueBulk($allFamilyIds, $currentYear);
            $famSabeelByYear = $allFamilyIds === [] ? [] : $this->nestFamSabeelByYear(
                MumineenSabeelModel::query()
                    ->whereIn('family_id', $allFamilyIds)
                    ->get(['family_id', 'year', 'sabeel'])
            );
            $famPaidByYear = $allFamilyIds === [] ? [] : $this->nestFamilyPaidByYear(
                DB::table('t_receipts')
                    ->whereIn('family_id', $allFamilyIds)
                    ->where('status', 'active')
                    ->select('family_id', 'year', DB::raw('SUM(amount) as paid'))
                    ->groupBy('family_id', 'year')
                    ->get()
            );

            $lastReceiptByFam = $allFamilyIds === [] ? [] : $this->lastReceiptIdsKeyed('family_id', $allFamilyIds);

            $blocks = [];
            foreach ($establishments as $est) {
                $eid = $est->establishment_id;
                $eRow = $estDueBulk[$eid] ?? [
                    'sabeel' => 0, 'paid' => 0.0, 'due_effective' => 0.0,
                ];

                $dueByYear = $this->buildDueByYearCells(
                    $yearsList,
                    $currentYear,
                    (float) ($eRow['due_effective'] ?? 0),
                    $estSabeelByYear[$eid] ?? [],
                    $estPaidByYear[$eid] ?? []
                );

                $lastEst = $lastReceiptByEst[$eid] ?? null;

                $partners = [];
                foreach ($linksByEst->get($eid, collect()) as $lnk) {
                    $fid = (int) $lnk->family_id;
                    $hof = $hofsByFamily->get($fid);
                    if (!$hof) {
                        continue;
                    }
                    $fRow = $famDueBulk[$fid] ?? [
                        'sabeel' => 0, 'paid' => 0.0, 'due_effective' => 0.0,
                    ];
                    $fDueByYear = $this->buildDueByYearCells(
                        $yearsList,
                        $currentYear,
                        (float) ($fRow['due_effective'] ?? 0),
                        $famSabeelByYear[$fid] ?? [],
                        $famPaidByYear[$fid] ?? []
                    );
                    $lastFam = $lastReceiptByFam[$fid] ?? null;
                    $partners[] = [
                        'label'       => $hof->name . ' (ITS ' . ($hof->its ?? '') . ')',
                        'hub'         => (int) ($fRow['sabeel'] ?? 0),
                        'due_by_year' => $fDueByYear,
                        'last_pay'    => $this->formatLastPaymentCompact($lastFam),
                    ];
                }
                usort($partners, fn ($a, $b) => strcmp($a['label'], $b['label']));

                $blocks[] = [
                    'establishment_name' => $est->name,
                    'hub'                => (int) ($eRow['sabeel'] ?? 0),
                    'due_by_year'        => $dueByYear,
                    'last_pay'           => $this->formatLastPaymentCompact($lastEst),
                    'partners'           => $partners,
                ];
            }

            $untagged = [];
            foreach ($untaggedHofs as $hof) {
                $fid = (int) $hof->family_id;
                $fRow = $famDueBulk[$fid] ?? [
                    'sabeel' => 0, 'paid' => 0.0, 'due_effective' => 0.0,
                ];
                $fDueByYear = $this->buildDueByYearCells(
                    $yearsList,
                    $currentYear,
                    (float) ($fRow['due_effective'] ?? 0),
                    $famSabeelByYear[$fid] ?? [],
                    $famPaidByYear[$fid] ?? []
                );
                $lastFam = $lastReceiptByFam[$fid] ?? null;
                $untagged[] = [
                    'label'       => $hof->name . ' (ITS ' . ($hof->its ?? '') . ')',
                    'hub'         => (int) ($fRow['sabeel'] ?? 0),
                    'due_by_year' => $fDueByYear,
                    'last_pay'    => $this->formatLastPaymentCompact($lastFam),
                ];
            }

            return $this->renderPdfResponse($blocks, $untagged, $currentYear, $yearsList);
        } catch (\Throwable $e) {
            return response()->json([
                'code'    => 500,
                'status'  => 'error',
                'message' => 'Payment follow-up PDF generation failed',
                'debug'   => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                ] : [],
            ], 500);
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int|string, array<string, int>>
     */
    private function nestEstSabeelByYear($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $eid = $row->establishment_id;
            $y = (string) $row->year;
            if (!isset($out[$eid])) {
                $out[$eid] = [];
            }
            $out[$eid][$y] = (int) $row->sabeel;
        }

        return $out;
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int|string, array<string, float>>
     */
    private function nestPaidByYear($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $eid = $row->establishment_id;
            $y = (string) $row->year;
            if (!isset($out[$eid])) {
                $out[$eid] = [];
            }
            $out[$eid][$y] = (float) $row->paid;
        }

        return $out;
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, array<string, int>>
     */
    private function nestFamSabeelByYear($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $fid = (int) $row->family_id;
            $y = (string) $row->year;
            if (!isset($out[$fid])) {
                $out[$fid] = [];
            }
            $out[$fid][$y] = (int) $row->sabeel;
        }

        return $out;
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, array<string, float>>
     */
    private function nestFamilyPaidByYear($rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $fid = (int) $row->family_id;
            $y = (string) $row->year;
            if (!isset($out[$fid])) {
                $out[$fid] = [];
            }
            $out[$fid][$y] = (float) $row->paid;
        }

        return $out;
    }

    /**
     * One cell per year (aligned under year column headers).
     *
     * @param array<string, int>   $sabeelByYear
     * @param array<string, float> $paidByYear
     * @return array<string, string>
     */
    private function buildDueByYearCells(
        array $yearsList,
        string $currentYear,
        float $currentYearEffectiveDue,
        array $sabeelByYear,
        array $paidByYear
    ): array {
        $cells = [];
        foreach ($yearsList as $y) {
            $year = (string) $y;
            $sabeel = (int) ($sabeelByYear[$year] ?? 0);
            $paid = (float) ($paidByYear[$year] ?? 0);
            $rawDue = max(0.0, $sabeel - $paid);
            $due = ($year === $currentYear) ? $currentYearEffectiveDue : $rawDue;
            $cells[$year] = $due > 0.005 ? number_format($due, 2) : '—';
        }

        return $cells;
    }

    /**
     * Latest active receipt per entity (one query for keys + one to hydrate).
     *
     * @param 'establishment_id'|'family_id' $column
     * @param array<int|string>              $ids
     * @return array<int|string, ReceiptModel|null>
     */
    private function lastReceiptIdsKeyed(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $idList = array_values(array_unique($ids));
        $placeholders = implode(',', array_fill(0, count($idList), '?'));

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $sql = "
                SELECT t.{$column} AS entity_id, MAX(t.id) AS last_id
                FROM t_receipts t
                INNER JOIN (
                    SELECT {$column}, MAX(`date`) AS md
                    FROM t_receipts
                    WHERE status = 'active' AND {$column} IS NOT NULL
                      AND {$column} IN ({$placeholders})
                    GROUP BY {$column}
                ) x ON x.{$column} = t.{$column} AND t.`date` = x.md AND t.status = 'active'
                GROUP BY t.{$column}
            ";
            $rows = DB::select($sql, $idList);
        } else {
            $sql = "
                SELECT {$column} AS entity_id, MAX(id) AS last_id
                FROM t_receipts
                WHERE status = 'active' AND {$column} IS NOT NULL
                  AND {$column} IN ({$placeholders})
                GROUP BY {$column}
            ";
            $rows = DB::select($sql, $idList);
        }

        $idMap = [];
        foreach ($rows as $row) {
            $idMap[(int) $row->last_id] = true;
        }
        if ($idMap === []) {
            return [];
        }

        $receipts = ReceiptModel::query()
            ->whereIn('id', array_keys($idMap))
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $rec = $receipts->get((int) $row->last_id);
            $entityId = $row->entity_id;
            if ($column === 'establishment_id') {
                $out[$entityId] = $rec;
                if (is_numeric($entityId)) {
                    $out[(int) $entityId] = $rec;
                }
            } else {
                $out[(int) $entityId] = $rec;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function distinctSabeelYearsSorted(): array
    {
        $rows = DB::select('
            SELECT DISTINCT year FROM t_establishment_sabeel WHERE year IS NOT NULL AND year != \'\'
            UNION
            SELECT DISTINCT year FROM t_mumineen_sabeel WHERE year IS NOT NULL AND year != \'\'
        ');
        $merged = [];
        foreach ($rows as $row) {
            $merged[] = (string) $row->year;
        }
        $merged = array_values(array_unique($merged));
        sort($merged, SORT_STRING);

        return $merged;
    }

    /** Compact last payment for narrow PDF column (stacked lines). */
    private function formatLastPaymentCompact(?ReceiptModel $r): string
    {
        if (!$r) {
            return '—';
        }
        $d = $r->date ? $r->date->format('d-m-y') : '';
        $amt = number_format((float) $r->amount, 0);
        $mode = $r->mode ?? '';

        return $amt . "\n" . $d . "\n" . $mode;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $untagged
     * @param array<int, string>               $reportYears
     */
    private function renderPdfResponse(array $blocks, array $untagged, string $currentYear, array $reportYears = [])
    {
        $generatedAt = now()->timezone('Asia/Kolkata')->format('d-m-Y H:i:s') . ' IST';
        $title = 'Payment follow-up (establishment-wise)';

        @ini_set('pcre.backtrack_limit', '10000000');
        @ini_set('pcre.recursion_limit', '10000000');

        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4-L',
            'orientation'   => 'L',
            'margin_left'   => 8,
            'margin_right'  => 8,
            'margin_top'    => 22,
            'margin_bottom' => 12,
            'margin_header' => 6,
        ]);

        $headerHtml = '<table style="width:100%;font-size:9px;border-bottom:1px solid #333;margin-bottom:4px;"><tr>'
            . '<td style="text-align:left;font-weight:bold;">' . htmlspecialchars($title) . '</td>'
            . '<td style="text-align:right;">Generated: ' . htmlspecialchars($generatedAt) . '</td>'
            . '</tr></table>';

        $mpdf->SetHTMLHeader($headerHtml, '', true);
        $mpdf->SetFooter('{PAGENO} / {nb}');
        $this->writePaymentFollowupPdfBody($mpdf, $title, $currentYear, $reportYears, $blocks, $untagged);

        $filename = 'payment_followup_establishment_' . now()->format('Y-m-d_His') . '.pdf';

        return response()->make($mpdf->Output('', 'S'), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control'       => 'public, max-age=0',
        ]);
    }

    private function emptyPdfResponse()
    {
        $dueService = app(DueCalculationService::class);

        return $this->renderPdfResponse([], [], $dueService->getCurrentYear(), $this->distinctSabeelYearsSorted());
    }

    /**
     * Split HTML across WriteHTML calls so each string stays under PHP PCRE limits (mPDF regex on full input).
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $untagged
     * @param array<int, string>               $reportYears
     */
    private function writePaymentFollowupPdfBody(
        Mpdf $mpdf,
        string $title,
        string $currentYear,
        array $reportYears,
        array $blocks,
        array $untagged
    ): void {
        $mpdf->WriteHTML(view('payment_followup_pdf_css')->render(), HTMLParserMode::HEADER_CSS);

        $estChunks = $this->chunkEstablishmentBlocksForPdf($blocks);
        $bodyInit = true;
        $rowSn = 1;

        foreach ($estChunks as $idx => $chunk) {
            $html = '';
            if ($idx === 0) {
                $html .= view('payment_followup_pdf_est_open', [
                    'title'       => $title,
                    'currentYear' => $currentYear,
                    'reportYears' => $reportYears,
                ])->render();
            }
            $html .= view('payment_followup_pdf_est_rows', [
                'blocks'      => $chunk,
                'reportYears' => $reportYears,
                'startSn'     => $rowSn,
            ])->render();
            foreach ($chunk as $b) {
                $rowSn += 1 + count($b['partners'] ?? []);
            }
            $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY, $bodyInit, false);
            $bodyInit = false;
        }

        $mpdf->WriteHTML(
            view('payment_followup_pdf_untagged_section_open', [
                'currentYear' => $currentYear,
                'reportYears' => $reportYears,
            ])->render(),
            HTMLParserMode::HTML_BODY,
            false,
            false
        );

        if ($untagged === []) {
            $mpdf->WriteHTML(
                view('payment_followup_pdf_untagged_rows', [
                    'reportYears' => $reportYears,
                    'empty'       => true,
                    'untagged'    => [],
                    'startSn'     => 1,
                ])->render(),
                HTMLParserMode::HTML_BODY,
                false,
                false
            );
        } else {
            $rowSn2 = 1;
            foreach (array_chunk($untagged, self::PDF_UNTAGGED_CHUNK) as $uChunk) {
                $mpdf->WriteHTML(
                    view('payment_followup_pdf_untagged_rows', [
                        'reportYears' => $reportYears,
                        'empty'       => false,
                        'untagged'    => $uChunk,
                        'startSn'     => $rowSn2,
                    ])->render(),
                    HTMLParserMode::HTML_BODY,
                    false,
                    false
                );
                $rowSn2 += count($uChunk);
            }
        }

        $mpdf->WriteHTML(
            view('payment_followup_pdf_close')->render(),
            HTMLParserMode::HTML_BODY,
            false,
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function chunkEstablishmentBlocksForPdf(array $blocks): array
    {
        if ($blocks === []) {
            return [[]];
        }
        $chunks = [];
        $current = [];
        $rowsInCurrent = 0;
        foreach ($blocks as $b) {
            $rowCount = 1 + count($b['partners'] ?? []);
            if ($current !== [] && $rowsInCurrent + $rowCount > self::PDF_MAX_EST_TABLE_ROWS) {
                $chunks[] = $current;
                $current = [];
                $rowsInCurrent = 0;
            }
            $current[] = $b;
            $rowsInCurrent += $rowCount;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
