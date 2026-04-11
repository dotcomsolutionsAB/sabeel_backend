<?php

namespace App\Http\Controllers;

use App\Models\EstablishmentModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\MumineenModel;
use App\Models\ReceiptModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenSabeelModel;
use App\Services\DueCalculationService;
use Mpdf\Mpdf;

class PaymentFollowupPdfController extends Controller
{
    /**
     * GET /establishment/payment-followup-pdf
     * Landscape A4 PDF: establishment hub + year-wise due, partner personal rows, last payment; then untagged families.
     */
    public function establishmentWisePdf()
    {
        try {
            $dueService = app(DueCalculationService::class);
            $currentYear = $dueService->getCurrentYear();
            $yearsList = $this->distinctSabeelYearsSorted();

            $establishments = EstablishmentModel::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get();

            $blocks = [];
            foreach ($establishments as $est) {
                $eid = $est->establishment_id;
                $eDue = $dueService->getEstablishmentDue($eid, $currentYear);
                $byYear = $dueService->getEstablishmentDueByYear($eid, $yearsList);
                $dueLines = $this->buildYearDueLines($byYear, $currentYear, $eDue['due_effective']);

                $lastEst = ReceiptModel::query()
                    ->where('establishment_id', $eid)
                    ->where('status', 'active')
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->first();

                $partnerLinks = MumineenEstablishmentModel::where('establishment_id', $eid)->get();
                $familyIds = $partnerLinks->pluck('family_id')->unique()->values()->all();

                $partners = [];
                foreach ($familyIds as $fid) {
                    $hof = MumineenModel::where('family_id', $fid)
                        ->where('hof_type', 'HOF')
                        ->where('status', 'active')
                        ->first();
                    if (!$hof) {
                        continue;
                    }
                    $fDue = $dueService->getFamilyDue($fid, $currentYear);
                    $fByYear = $dueService->getFamilyDueByYear($fid, $yearsList);
                    $fDueLines = $this->buildYearDueLines($fByYear, $currentYear, $fDue['due_effective']);

                    $lastFam = ReceiptModel::query()
                        ->where('family_id', $fid)
                        ->where('status', 'active')
                        ->orderByDesc('date')
                        ->orderByDesc('id')
                        ->first();

                    $partners[] = [
                        'label'      => $hof->name . ' (ITS ' . ($hof->its ?? '') . ')',
                        'hub'        => (int) $fDue['sabeel'],
                        'paid'       => (float) $fDue['paid'],
                        'due_lines'  => $fDueLines,
                        'last_pay'   => $this->formatLastPayment($lastFam),
                    ];
                }
                usort($partners, fn ($a, $b) => strcmp($a['label'], $b['label']));

                $blocks[] = [
                    'establishment_name' => $est->name,
                    'hub'                => (int) $eDue['sabeel'],
                    'paid'               => (float) $eDue['paid'],
                    'due_lines'          => $dueLines,
                    'last_pay'           => $this->formatLastPayment($lastEst),
                    'partners'           => $partners,
                ];
            }

            $linkedFamilyIds = MumineenEstablishmentModel::query()
                ->distinct()
                ->pluck('family_id');

            $untaggedQuery = MumineenModel::query()
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->orderBy('name');
            if ($linkedFamilyIds->isNotEmpty()) {
                $untaggedQuery->whereNotIn('family_id', $linkedFamilyIds->all());
            }

            $untagged = [];
            foreach ($untaggedQuery->get() as $hof) {
                $fid = $hof->family_id;
                $fDue = $dueService->getFamilyDue($fid, $currentYear);
                $fByYear = $dueService->getFamilyDueByYear($fid, $yearsList);
                $fDueLines = $this->buildYearDueLines($fByYear, $currentYear, $fDue['due_effective']);

                $lastFam = ReceiptModel::query()
                    ->where('family_id', $fid)
                    ->where('status', 'active')
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->first();

                $untagged[] = [
                    'label'     => $hof->name . ' (ITS ' . ($hof->its ?? '') . ')',
                    'hub'       => (int) $fDue['sabeel'],
                    'paid'      => (float) $fDue['paid'],
                    'due_lines' => $fDueLines,
                    'last_pay'  => $this->formatLastPayment($lastFam),
                ];
            }

            $generatedAt = now()->format('d-m-Y H:i:s');
            $title = 'Payment follow-up (establishment-wise)';

            $html = view('payment_followup_establishment_pdf', [
                'title'        => $title,
                'currentYear'  => $currentYear,
                'generatedAt'  => $generatedAt,
                'blocks'       => $blocks,
                'untagged'     => $untagged,
            ])->render();

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
            $mpdf->WriteHTML($html);

            $filename = 'payment_followup_establishment_' . now()->format('Y-m-d_His') . '.pdf';

            return response()->make($mpdf->Output('', 'S'), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control'       => 'public, max-age=0',
            ]);
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
     * @return array<int, string>
     */
    private function distinctSabeelYearsSorted(): array
    {
        $a = EstablishmentSabeelModel::query()->distinct()->pluck('year')->filter()->all();
        $b = MumineenSabeelModel::query()->distinct()->pluck('year')->filter()->all();
        $merged = array_values(array_unique(array_merge(
            array_map('strval', $a),
            array_map('strval', $b)
        )));
        sort($merged, SORT_STRING);

        return $merged;
    }

    /**
     * @param array<int, array{year: string, sabeel: int, paid: float, due: float}> $byYear
     */
    private function buildYearDueLines(array $byYear, string $currentYear, float $currentYearEffectiveDue): string
    {
        $lines = [];
        foreach ($byYear as $row) {
            $y = (string) $row['year'];
            $due = ($y === $currentYear) ? $currentYearEffectiveDue : (float) $row['due'];
            if ($due > 0.005) {
                $lines[] = $y . ': Rs. ' . number_format($due, 2);
            }
        }
        if ($lines === []) {
            return '—';
        }

        return implode("\n", $lines);
    }

    private function formatLastPayment(?ReceiptModel $r): string
    {
        if (!$r) {
            return '—';
        }
        $d = $r->date ? $r->date->format('d-m-Y') : '';

        return 'Rs. ' . number_format((float) $r->amount, 2) . ' | ' . $d . ' | ' . ($r->mode ?? '');
    }
}
