<?php

namespace App\Http\Controllers;

use App\Exports\GenericExcelExport;
use App\Helpers\ExcelExportHelper;
use App\Models\EstablishmentSlabGroupModel;
use App\Services\EstablishmentSlabAggregationService;
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
     * Body: {
     *   "year": "2025-26",
     *   "type": "personal" | "establishment" | "family",
     *   "export_excel": true,
     *   "merge_establishment_groups": true
     * }
     * Establishment: if merge_establishment_groups is omitted, it defaults to true when at least one active
     * slab merge group exists, otherwise false. Pass false explicitly to always use per-establishment buckets.
     */
    public function breakdown(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'year'                         => 'required|string|max:10',
                'type'                         => 'required|string|in:personal,establishment,family',
                'export_excel'                 => 'nullable|boolean',
                'merge_establishment_groups'   => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $year = trim((string) $request->input('year'));
            $type = strtolower(trim((string) $request->input('type')));
            $isPersonal = in_array($type, ['personal', 'family'], true);
            $mergeEstablishmentGroups = $this->resolveMergeEstablishmentGroupsForBreakdown($request, $isPersonal);

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
                $rows = app(EstablishmentSlabAggregationService::class)
                    ->establishmentBreakdownRows($year, $mergeEstablishmentGroups);
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
                    'category'        => $this->categoryLabel($index),
                    'yearly_sabeel'   => $yearly,
                    'monthly_sabeel'  => $monthlySabeel,
                    'count'           => $count,
                    'total_amount'    => $totalAmount,
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
                'year'                         => $year,
                'type'                         => $isPersonal ? 'personal' : 'establishment',
                'merge_establishment_groups'   => !$isPersonal && $mergeEstablishmentGroups,
                'items'                        => $items,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Sabeel slab breakdown failed');
        }
    }

    /**
     * Rows in a single slab from {@see breakdown()} for popup detail.
     * GET or POST /sabeel/slab-detail
     * Input (JSON body and/or query string; body wins on duplicate keys):
     *  - year (required): sabeel year, e.g. "2025-26". Aliases: sabeel_year, sabeelYear, financial_year
     *  - type: personal | establishment | family (required)
     *  - slab (required, int): establishment = yearly sabeel; personal = monthly slab (round(yearly/12)) unless yearly_sabeel is sent
     *  - yearly_sabeel (optional, int): personal — exact yearly from row; establishment + merge — combined yearly from row
     *  - merge_establishment_groups (optional, bool): establishment only; same default as slab-breakdown when omitted.
     */
    public function slabDetail(Request $request)
    {
        try {
            $payload = $this->slabDetailPayload($request);

            $validator = Validator::make($payload, [
                'year'                         => 'required|string|max:10',
                'type'                         => 'required|string|in:personal,establishment,family',
                'slab'                         => 'required|numeric|min:0',
                'yearly_sabeel'                => 'nullable|numeric|min:0',
                'merge_establishment_groups'   => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $year = trim((string) $payload['year']);
            $type = strtolower(trim((string) ($payload['type'] ?? '')));
            $isPersonal = in_array($type, ['personal', 'family'], true);
            $slab = (int) round((float) ($payload['slab'] ?? 0));
            $yearlyExact = $payload['yearly_sabeel'] ?? null;
            $yearlyExact = $yearlyExact !== null && $yearlyExact !== '' ? (int) round((float) $yearlyExact) : null;
            $mergeEstablishmentGroups = $this->resolveMergeEstablishmentGroupsFromPayload($payload, $isPersonal);

            if ($isPersonal) {
                $q = DB::table('t_mumineen_sabeel as ms')
                    ->join('t_mumineen as m', function ($join) {
                        $join->on('m.family_id', '=', 'ms.family_id')
                            ->where('m.hof_type', 'HOF')
                            ->where('m.status', 'active');
                    })
                    ->where('ms.year', $year);

                if ($yearlyExact !== null) {
                    $q->where('ms.sabeel', $yearlyExact);
                } else {
                    $q->whereRaw('ROUND(ms.sabeel / 12.0) = ?', [$slab]);
                }

                $rows = $q->orderBy('m.name')
                    ->select(
                        'm.family_id',
                        'm.name',
                        'm.its',
                        'ms.sabeel as yearly_sabeel'
                    )
                    ->get();

                $list = $rows->map(function ($r) {
                    $y = (int) $r->yearly_sabeel;

                    return [
                        'family_id'       => (int) $r->family_id,
                        'name'           => (string) $r->name,
                        'its'            => $r->its !== null ? (string) $r->its : '',
                        'yearly_sabeel'  => $y,
                        'monthly_sabeel' => (int) round($y / 12.0),
                    ];
                })->values()->all();
            } else {
                $list = app(EstablishmentSlabAggregationService::class)->establishmentSlabDetailItems(
                    $year,
                    $slab,
                    $yearlyExact,
                    $mergeEstablishmentGroups
                );
            }

            return $this->success('Sabeel slab detail', [
                'year'                         => $year,
                'type'                         => $isPersonal ? 'personal' : 'establishment',
                'slab'                         => $slab,
                'yearly_sabeel'                => $yearlyExact,
                'merge_establishment_groups'   => !$isPersonal && $mergeEstablishmentGroups,
                'count'                        => count($list),
                'items'                        => $list,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Sabeel slab detail failed');
        }
    }

    private function resolveMergeEstablishmentGroupsForBreakdown(Request $request, bool $isPersonal): bool
    {
        if ($isPersonal) {
            return false;
        }
        if ($request->exists('merge_establishment_groups')) {
            return $request->boolean('merge_establishment_groups');
        }

        return EstablishmentSlabGroupModel::query()->where('is_active', true)->exists();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveMergeEstablishmentGroupsFromPayload(array $payload, bool $isPersonal): bool
    {
        if ($isPersonal) {
            return false;
        }
        if (array_key_exists('merge_establishment_groups', $payload)
            && $payload['merge_establishment_groups'] !== null
            && $payload['merge_establishment_groups'] !== '') {
            return filter_var($payload['merge_establishment_groups'], FILTER_VALIDATE_BOOLEAN);
        }

        return EstablishmentSlabGroupModel::query()->where('is_active', true)->exists();
    }

    /**
     * Merge query + body and resolve year from common frontend keys.
     *
     * @return array<string, mixed>
     */
    private function slabDetailPayload(Request $request): array
    {
        $data = array_merge($request->query(), $request->all());

        $yearMissing = !isset($data['year']) || $data['year'] === '' || $data['year'] === null;
        if ($yearMissing) {
            foreach (['sabeel_year', 'sabeelYear', 'financial_year'] as $alt) {
                if (isset($data[$alt]) && $data[$alt] !== '' && $data[$alt] !== null) {
                    $data['year'] = $data[$alt];
                    break;
                }
            }
        }

        return $data;
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
