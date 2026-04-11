<?php

namespace App\Http\Controllers;

use App\Exports\GenericExcelExport;
use App\Helpers\ExcelExportHelper;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class SabeelSlabReportController extends Controller
{
    use ApiResponse;

    /**
     * Breakdown of families or establishments grouped by yearly sabeel slab for a year.
     * Categories A, B, … assigned highest slab to A (then AA, AB, … beyond Z).
     * For personal, total_amount = count × monthly_sabeel (monthly-scale total).
     * Only active families (HOF status active) and active establishments are included.
     *
     * POST /sabeel/slab-breakdown
     * Body: { "year": "2025-26", "type": "personal" | "establishment" | "family", "export_excel": true }
     */
    public function breakdown(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'year'         => 'required|string|max:10',
                'type'         => 'required|string|in:personal,establishment,family',
                'export_excel' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $year = trim((string) $request->input('year'));
            $type = strtolower(trim((string) $request->input('type')));
            $isPersonal = in_array($type, ['personal', 'family'], true);

            if ($isPersonal) {
                $rows = DB::table('t_mumineen_sabeel as ms')
                    ->join('t_mumineen as m', function ($join) {
                        $join->on('m.family_id', '=', 'ms.family_id')
                            ->where('m.hof_type', 'HOF')
                            ->where('m.status', 'active');
                    })
                    ->where('ms.year', $year)
                    ->select('ms.sabeel', DB::raw('COUNT(DISTINCT ms.family_id) as cnt'))
                    ->groupBy('ms.sabeel')
                    ->orderByDesc('ms.sabeel')
                    ->get();
            } else {
                $rows = DB::table('t_establishment_sabeel as es')
                    ->join('t_establishment as e', function ($join) {
                        $join->on('e.establishment_id', '=', 'es.establishment_id')
                            ->where('e.status', 'active');
                    })
                    ->where('es.year', $year)
                    ->select('es.sabeel', DB::raw('COUNT(DISTINCT es.establishment_id) as cnt'))
                    ->groupBy('es.sabeel')
                    ->orderByDesc('es.sabeel')
                    ->get();
            }

            $items = [];
            $index = 0;
            foreach ($rows as $row) {
                $yearly = (int) $row->sabeel;
                $count = (int) $row->cnt;
                $monthlySabeel = $isPersonal
                    ? (int) round($yearly / 12.0)
                    : $yearly;
                $totalAmount = $isPersonal
                    ? $count * $monthlySabeel
                    : $count * $yearly;
                $items[] = [
                    'category'       => $this->categoryLabel($index),
                    'monthly_sabeel' => $monthlySabeel,
                    'count'          => $count,
                    'total_amount'   => $totalAmount,
                ];
                $index++;
            }

            if ($request->boolean('export_excel')) {
                $sabeelHeading = $isPersonal ? 'Monthly Sabeel' : 'Yearly Sabeel';
                $excelRows = [];
                foreach ($items as $row) {
                    $excelRows[] = [
                        $row['category'],
                        $row['monthly_sabeel'],
                        $row['count'],
                        $row['total_amount'],
                    ];
                }
                $grandTotal = array_sum(array_column($items, 'total_amount'));
                $excelRows[] = ['Grand total', '', '', $grandTotal];

                $export = new GenericExcelExport(
                    $excelRows,
                    ['Category', $sabeelHeading, 'Count', 'Total Amount'],
                    [
                        'A' => Alignment::HORIZONTAL_CENTER,
                        'B' => Alignment::HORIZONTAL_RIGHT,
                        'C' => Alignment::HORIZONTAL_CENTER,
                        'D' => Alignment::HORIZONTAL_RIGHT,
                    ]
                );

                $prefix = $isPersonal ? 'sabeel_slab_personal' : 'sabeel_slab_establishment';

                return ExcelExportHelper::store($export, 'sabeel', "{$prefix}_{$year}");
            }

            return $this->success('Sabeel slab breakdown', [
                'year' => $year,
                'type' => $isPersonal ? 'personal' : 'establishment',
                'items' => $items,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Sabeel slab breakdown failed');
        }
    }

    /**
     * 0 => A, 1 => B, …, 25 => Z, 26 => AA, …
     */
    private function categoryLabel(int $zeroBasedIndex): string
    {
        $n = $zeroBasedIndex;
        $s = '';
        do {
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $s;
    }
}
