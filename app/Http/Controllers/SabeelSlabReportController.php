<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SabeelSlabReportController extends Controller
{
    use ApiResponse;

    /**
     * Breakdown of families or establishments grouped by yearly sabeel slab for a year.
     * Categories A, B, … assigned highest slab to A (then AA, AB, … beyond Z).
     * For personal, total_amount = count × monthly_sabeel (monthly-scale total).
     *
     * POST /sabeel/slab-breakdown
     * Body: { "year": "2025-26", "type": "personal" | "establishment" | "family" }
     */
    public function breakdown(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'year' => 'required|string|max:10',
                'type' => 'required|string|in:personal,establishment,family',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $year = trim((string) $request->input('year'));
            $type = strtolower(trim((string) $request->input('type')));
            $isPersonal = in_array($type, ['personal', 'family'], true);

            if ($isPersonal) {
                $rows = DB::table('t_mumineen_sabeel')
                    ->where('year', $year)
                    ->select('sabeel', DB::raw('COUNT(DISTINCT family_id) as cnt'))
                    ->groupBy('sabeel')
                    ->orderByDesc('sabeel')
                    ->get();
            } else {
                $rows = DB::table('t_establishment_sabeel')
                    ->where('year', $year)
                    ->select('sabeel', DB::raw('COUNT(DISTINCT establishment_id) as cnt'))
                    ->groupBy('sabeel')
                    ->orderByDesc('sabeel')
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
