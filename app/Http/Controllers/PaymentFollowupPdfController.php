<?php

namespace App\Http\Controllers;

use App\Exports\PaymentFollowupExcelExport;
use App\Helpers\ExcelExportHelper;
use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;
use App\Services\DueCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PaymentFollowupPdfController extends Controller
{
    /** Max table rows per WriteHTML chunk (establishment + partner lines). */
    private const PDF_MAX_EST_TABLE_ROWS = 300;

    /** Max untagged family rows per WriteHTML chunk. */
    private const PDF_UNTAGGED_CHUNK = 80;
    /**
     * GET|POST /establishment/payment-followup-pdf
     * Query/body: type = excel|pdf. Omitted or invalid defaults to excel.
     * Bulk-loaded data only (avoids N+1 / gateway timeouts). Includes HOF mobile as Phone.
     */
    public function establishmentWisePdf(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        $outputType = $this->resolvePaymentFollowupOutputType($request);

        try {
            $dueService = app(DueCalculationService::class);
            $currentYear = $dueService->getCurrentYear();
            $reportYears = $this->resolvePaymentFollowupThreeYears($currentYear);
            $reportYearLabels = [];
            foreach ($reportYears as $yr) {
                $reportYearLabels[$yr] = $this->formatDueYearHeading($yr);
            }

            $establishments = EstablishmentModel::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            if ($establishments->isEmpty()) {
                return $this->emptyPaymentFollowupResponse($outputType);
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

                $dueCells = $this->buildPaymentFollowupDueCells(
                    $reportYears,
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
                    $fDueCells = $this->buildPaymentFollowupDueCells(
                        $reportYears,
                        $currentYear,
                        (float) ($fRow['due_effective'] ?? 0),
                        $famSabeelByYear[$fid] ?? [],
                        $famPaidByYear[$fid] ?? []
                    );
                    $lastFam = $lastReceiptByFam[$fid] ?? null;
                    $partners[] = [
                        'label'       => $hof->name . ' (ITS ' . ($hof->its ?? '') . ')',
                        'phone'      => $this->formatHofPhone($hof),
                        'hub'         => (int) ($fRow['sabeel'] ?? 0),
                        'due_cells'   => $fDueCells,
                        'last_pay'    => $this->formatLastPaymentCompact($lastFam),
                        'is_takhmeen_updated' => (bool) ($hof->is_takhmeen_updated ?? false),
                    ];
                }
                usort($partners, fn ($a, $b) => strcmp($a['label'], $b['label']));

                $estPhone = $partners === [] ? '—' : ($partners[0]['phone'] ?? '—');

                $blocks[] = [
                    'establishment_name' => $est->name,
                    'phone'              => $estPhone,
                    'hub'                => (int) ($eRow['sabeel'] ?? 0),
                    'due_cells'          => $dueCells,
                    'last_pay'           => $this->formatLastPaymentCompact($lastEst),
                    'is_takhmeen_updated' => (bool) ($est->is_takhmeen_updated ?? false),
                    'partners'           => $partners,
                ];
            }

            $untagged = [];
            foreach ($untaggedHofs as $hof) {
                $fid = (int) $hof->family_id;
                $fRow = $famDueBulk[$fid] ?? [
                    'sabeel' => 0, 'paid' => 0.0, 'due_effective' => 0.0,
                ];
                $fDueCells = $this->buildPaymentFollowupDueCells(
                    $reportYears,
                    $currentYear,
                    (float) ($fRow['due_effective'] ?? 0),
                    $famSabeelByYear[$fid] ?? [],
                    $famPaidByYear[$fid] ?? []
                );
                $lastFam = $lastReceiptByFam[$fid] ?? null;
                $untagged[] = [
                    'label'       => $hof->name . ' (ITS ' . ($hof->its ?? '') . ')',
                    'phone'       => $this->formatHofPhone($hof),
                    'hub'         => (int) ($fRow['sabeel'] ?? 0),
                    'due_cells'   => $fDueCells,
                    'last_pay'    => $this->formatLastPaymentCompact($lastFam),
                    'is_takhmeen_updated' => (bool) ($hof->is_takhmeen_updated ?? false),
                ];
            }

            if ($outputType === 'excel') {
                return $this->renderExcelResponse(
                    $blocks,
                    $untagged,
                    $currentYear,
                    $reportYears,
                    $reportYearLabels
                );
            }

            return $this->renderPdfResponse($blocks, $untagged, $currentYear, $reportYears, $reportYearLabels);
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
     * PDF shows exactly three Due columns (oldest → current). First column = all raw dues for years
     * strictly before the middle year (previous arrears + that oldest displayed year). Middle = raw due
     * for middle year. Last = effective due for current year when it matches $currentYear, else raw.
     *
     * @param array{0: string, 1: string, 2: string} $reportYearsAsc
     * @param array<string, int>                     $sabeelByYear
     * @param array<string, float>                   $paidByYear
     * @return array{0: string, 1: string, 2: string}
     */
    private function buildPaymentFollowupDueCells(
        array $reportYearsAsc,
        string $currentYear,
        float $currentYearEffectiveDue,
        array $sabeelByYear,
        array $paidByYear
    ): array {
        $y0 = (string) $reportYearsAsc[0];
        $y1 = (string) $reportYearsAsc[1];
        $y2 = (string) $reportYearsAsc[2];

        $yearsToScan = array_unique(array_merge(array_keys($sabeelByYear), array_keys($paidByYear)));
        $sumBeforeMiddle = 0.0;
        foreach ($yearsToScan as $yStr) {
            if ($yStr < $y1) {
                $sabeel = (int) ($sabeelByYear[$yStr] ?? 0);
                $paid = (float) ($paidByYear[$yStr] ?? 0);
                $sumBeforeMiddle += max(0.0, $sabeel - $paid);
            }
        }

        $s1 = (int) ($sabeelByYear[$y1] ?? 0);
        $p1 = (float) ($paidByYear[$y1] ?? 0);
        $dueMiddle = max(0.0, $s1 - $p1);

        $s2 = (int) ($sabeelByYear[$y2] ?? 0);
        $p2 = (float) ($paidByYear[$y2] ?? 0);
        $rawLast = max(0.0, $s2 - $p2);
        $dueLast = ($y2 === $currentYear) ? $currentYearEffectiveDue : $rawLast;

        // Numeric keys so duplicate year labels (sparse t_year) still produce three columns.
        return [
            0 => $sumBeforeMiddle > 0.005 ? number_format($sumBeforeMiddle, 2) : '—',
            1 => $dueMiddle > 0.005 ? number_format($dueMiddle, 2) : '—',
            2 => $dueLast > 0.005 ? number_format($dueLast, 2) : '—',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolvePaymentFollowupThreeYears(string $currentYear): array
    {
        $y2 = $currentYear;
        if (!Schema::hasTable('t_year')) {
            return [$y2, $y2, $y2];
        }
        $y1 = (string) (YearModel::where('year', '<', $y2)->orderBy('year', 'desc')->value('year') ?? $y2);
        $y0 = (string) (YearModel::where('year', '<', $y1)->orderBy('year', 'desc')->value('year') ?? $y1);

        return [$y0, $y1, $y2];
    }

    /** e.g. 24-25 → 2024-25 for PDF headers */
    private function formatDueYearHeading(string $year): string
    {
        if (preg_match('/^\d{2}-\d{2}$/', $year)) {
            return '20' . $year;
        }

        return $year;
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

    /** Single-line last payment to keep PDF row height minimal. */
    private function formatLastPaymentCompact(?ReceiptModel $r): string
    {
        if (!$r) {
            return '—';
        }
        $d = $r->date ? $r->date->format('d-m-y') : '';
        $amt = number_format((float) $r->amount, 0);
        $mode = trim((string) ($r->mode ?? ''));

        return $amt . ' / ' . $d . ($mode !== '' ? ' / ' . $mode : '');
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $untagged
     * @param array{0: string, 1: string, 2: string} $reportYears
     * @param array<string, string>                  $reportYearLabels
     */
    private function renderPdfResponse(
        array $blocks,
        array $untagged,
        string $currentYear,
        array $reportYears,
        array $reportYearLabels
    )
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
            'margin_top'    => 18,
            'margin_bottom' => 10,
            'margin_header' => 5,
        ]);

        $headerHtml = '<table style="width:100%;font-size:9px;border-bottom:1px solid #333;margin-bottom:2px;"><tr>'
            . '<td style="text-align:left;font-weight:bold;">' . htmlspecialchars($title) . '</td>'
            . '<td style="text-align:right;">Generated: ' . htmlspecialchars($generatedAt) . '</td>'
            . '</tr></table>';

        $mpdf->SetHTMLHeader($headerHtml, '', true);
        $mpdf->SetFooter('{PAGENO} / {nb}');
        $this->writePaymentFollowupPdfBody($mpdf, $title, $currentYear, $reportYears, $reportYearLabels, $blocks, $untagged);

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

        $cur = $dueService->getCurrentYear();
        $reportYears = $this->resolvePaymentFollowupThreeYears($cur);
        $reportYearLabels = [];
        foreach ($reportYears as $yr) {
            $reportYearLabels[$yr] = $this->formatDueYearHeading($yr);
        }

        return $this->renderPdfResponse([], [], $cur, $reportYears, $reportYearLabels);
    }

    private function emptyPaymentFollowupResponse(string $outputType)
    {
        if ($outputType === 'excel') {
            $dueService = app(DueCalculationService::class);
            $cur = $dueService->getCurrentYear();
            $reportYears = $this->resolvePaymentFollowupThreeYears($cur);
            $reportYearLabels = [];
            foreach ($reportYears as $yr) {
                $reportYearLabels[$yr] = $this->formatDueYearHeading($yr);
            }

            return $this->renderExcelResponse([], [], $cur, $reportYears, $reportYearLabels);
        }

        return $this->emptyPdfResponse();
    }

    private function resolvePaymentFollowupOutputType(Request $request): string
    {
        $t = strtolower(trim((string) $request->input('type', 'excel')));
        if (in_array($t, ['excel', 'pdf'], true)) {
            return $t;
        }

        return 'excel';
    }

    private function formatHofPhone(MumineenModel $hof): string
    {
        $m = trim((string) ($hof->mobile ?? ''));

        return $m !== '' ? $m : '—';
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $untagged
     * @param array{0: string, 1: string, 2: string} $reportYears
     * @param array<string, string>             $reportYearLabels
     */
    private function renderExcelResponse(
        array $blocks,
        array $untagged,
        string $currentYear,
        array $reportYears,
        array $reportYearLabels
    ) {
        $headings = array_merge(
            ['SN', 'Establishment / Partner', 'Phone', "Hub ({$currentYear})"],
            array_map(fn ($yr) => 'Due ' . ($reportYearLabels[$yr] ?? $yr), $reportYears),
            ['Last payment (amt / date / mode)']
        );

        $excelRows = [];
        $highlightRows = [];
        $excelRowNo = 2;
        $sn = 1;
        foreach ($blocks as $block) {
            $row = [
                (string) $sn,
                $block['establishment_name'],
                $block['phone'] ?? '—',
                number_format((int) ($block['hub'] ?? 0)),
            ];
            foreach ($reportYears as $i => $_) {
                $row[] = $block['due_cells'][$i] ?? '—';
            }
            $row[] = $block['last_pay'] ?? '—';
            $excelRows[] = $row;
            if (!(bool) ($block['is_takhmeen_updated'] ?? false)) {
                $highlightRows[] = $excelRowNo;
            }
            $excelRowNo++;
            $sn++;

            foreach ($block['partners'] ?? [] as $p) {
                $prow = [
                    '',
                    $p['label'] ?? '',
                    $p['phone'] ?? '—',
                    number_format((int) ($p['hub'] ?? 0)),
                ];
                foreach ($reportYears as $i => $_) {
                    $prow[] = $p['due_cells'][$i] ?? '—';
                }
                $prow[] = $p['last_pay'] ?? '—';
                $excelRows[] = $prow;
                if (!(bool) ($p['is_takhmeen_updated'] ?? false)) {
                    $highlightRows[] = $excelRowNo;
                }
                $excelRowNo++;
            }
        }

        if ($untagged !== []) {
            if ($blocks !== []) {
                $excelRows[] = array_fill(0, count($headings), '');
            $excelRowNo++;
            }
            $titleRow = array_fill(0, count($headings), '');
            $titleRow[1] = 'Families not linked to any establishment';
            $excelRows[] = $titleRow;
            $excelRowNo++;
            $sn2 = 1;
            foreach ($untagged as $u) {
                $urow = [
                    (string) $sn2,
                    $u['label'] ?? '',
                    $u['phone'] ?? '—',
                    number_format((int) ($u['hub'] ?? 0)),
                ];
                foreach ($reportYears as $i => $_) {
                    $urow[] = $u['due_cells'][$i] ?? '—';
                }
                $urow[] = $u['last_pay'] ?? '—';
                $excelRows[] = $urow;
                if (!(bool) ($u['is_takhmeen_updated'] ?? false)) {
                    $highlightRows[] = $excelRowNo;
                }
                $excelRowNo++;
                $sn2++;
            }
        } elseif ($blocks === [] && $untagged === []) {
            $msg = array_fill(0, count($headings), '');
            $msg[1] = 'No active establishments.';
            $excelRows[] = $msg;
            $excelRowNo++;
        }

        $colCount = count($headings);
        $letters = range('A', 'Z');
        $align = [
            'A' => Alignment::HORIZONTAL_CENTER,
            'B' => Alignment::HORIZONTAL_LEFT,
            'C' => Alignment::HORIZONTAL_LEFT,
        ];
        $lastColIndex = $colCount - 1;
        $lastLetter = $lastColIndex < 26 ? $letters[$lastColIndex] : 'Z';
        for ($i = 3; $i < $colCount - 1; $i++) {
            if ($i < 26) {
                $align[$letters[$i]] = Alignment::HORIZONTAL_RIGHT;
            }
        }
        $align[$lastLetter] = Alignment::HORIZONTAL_LEFT;

        $export = new PaymentFollowupExcelExport(
            $excelRows,
            $headings,
            $align,
            $highlightRows
        );

        return ExcelExportHelper::store($export, 'sabeel', 'payment_followup_establishment');
    }

    /**
     * Split HTML across WriteHTML calls so each string stays under PHP PCRE limits (mPDF regex on full input).
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $untagged
     * @param array{0: string, 1: string, 2: string} $reportYears
     * @param array<string, string>                  $reportYearLabels
     */
    private function writePaymentFollowupPdfBody(
        Mpdf $mpdf,
        string $title,
        string $currentYear,
        array $reportYears,
        array $reportYearLabels,
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
                    'title'              => $title,
                    'currentYear'        => $currentYear,
                    'reportYears'        => $reportYears,
                    'reportYearLabels'   => $reportYearLabels,
                ])->render();
            }
            $html .= view('payment_followup_pdf_est_rows', [
                'blocks'      => $chunk,
                'reportYears' => $reportYears,
                'startSn'     => $rowSn,
            ])->render();
            foreach ($chunk as $b) {
                $rowSn += 1;
            }
            $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY, $bodyInit, false);
            $bodyInit = false;
        }

        $mpdf->WriteHTML(
            view('payment_followup_pdf_untagged_section_open', [
                'currentYear'      => $currentYear,
                'reportYears'      => $reportYears,
                'reportYearLabels' => $reportYearLabels,
            ])->render(),
            HTMLParserMode::HTML_BODY,
            false,
            false
        );

        if ($untagged === []) {
            $mpdf->WriteHTML(
                view('payment_followup_pdf_untagged_rows', [
                    'reportYears'      => $reportYears,
                    'reportYearLabels' => $reportYearLabels,
                    'empty'            => true,
                    'untagged'         => [],
                    'startSn'          => 1,
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
                        'reportYears'      => $reportYears,
                        'reportYearLabels' => $reportYearLabels,
                        'empty'            => false,
                        'untagged'         => $uChunk,
                        'startSn'          => $rowSn2,
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
