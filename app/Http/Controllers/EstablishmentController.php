<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Traits\SmartSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use App\Models\EstablishmentModel;
use App\Models\MumineenModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;

class EstablishmentController extends Controller
{
    //
    use ApiResponse, SmartSearch;

    /**
     * CREATE
     * POST /establishment/create
     * Auto-generate establishment_id (10 digit unique)
     */
    public function create(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'    => 'required|string|max:255',
                'address' => 'required|string',
                'status'  => 'required|in:active,closed',
                'type'    => 'required|in:business,manufacturer',
                'remarks' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $estNo = $this->generateUniqueEstablishmentNo();

            $row = EstablishmentModel::create([
                'establishment_id' => $estNo,
                'name'             => $request->name,
                'address'          => $request->address,
                'status'           => $request->status,
                'type'             => $request->type,
                'remarks'          => $request->remarks ?? null,
            ]);

            return $this->success('Data saved successfully', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment create failed');
        }
    }

    /**
     * FETCH list/single
     * POST /establishment/retrieve/{id?}
     * Body:
     * {
     *   "search": "",
     *   "filter": "due|prev_due|new_takhmeen_pending|not_tagged|manufacturer",
     *   "limit": 10,
     *   "offset": 0
     * }
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            [$currentYear, $prevYear] = $this->resolveYears();

            // SINGLE
            if ($id !== null) {
                $est = EstablishmentModel::find($id);
                if (!$est) return $this->error('Establishment not found.', 404);

                $payload = $this->buildPayload(collect([$est]), $currentYear, $prevYear);
                return $this->success('Data fetched successfully', $payload, 200);
            }

            // LIST
            $limit  = max(1, (int) $request->input('limit', 10));
            $offset = max(0, (int) $request->input('offset', 0));
            $search = trim((string) $request->input('search', ''));
            $filter = trim((string) $request->input('filter', ''));

            $q = EstablishmentModel::query()
                ->select('id','establishment_id','name','address','type','status')
                ->orderBy('name','asc');

            // Smart search: in establishment name/address/establishment_id AND partner mumineen (its/name/sector) AND family member its
            if ($search !== '') {
                $this->applySmartSearch($q, $search, ['name', 'address', 'establishment_id'], function ($query, $keyword) {
                    $query->orWhereExists(function($sub) use ($keyword) {
                          $sub->from('t_mumineen_establishment as me')
                              ->join('t_mumineen as m', 'm.family_id', '=', 'me.family_id')
                              ->whereColumn('me.establishment_id', 't_establishment.establishment_id')
                            ->where(function($x) use ($keyword) {
                                $x->where('m.its', 'like', "%{$keyword}%")
                                  ->orWhere('m.name', 'like', "%{$keyword}%")
                                  ->orWhere('m.sector', 'like', "%{$keyword}%");
                              });
                      });
                });
            }

            // FILTERS
            if ($filter !== '') {
                $q = $this->applyFilter($q, $filter, $currentYear, $prevYear);
            }

            $total = (clone $q)->count();
            $rows  = $q->skip($offset)->take($limit)->get();

            $data = $this->buildPayload($rows, $currentYear, $prevYear);

            return $this->success('Data fetched successfully', $data, 200, [
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'count'  => count($data),
                    'total'  => $total,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment fetch failed');
        }
    }

    /**
     * UPDATE
     * POST /establishment/update/{id}
     */
    public function edit(Request $request, $establishment_id)
    {
        try {
            $est = EstablishmentModel::where('establishment_id', (string)$establishment_id)->first();
            if (!$est) return $this->error('Establishment not found.', 404);

            $validator = Validator::make($request->all(), [
                'name'    => 'required|string|max:255',
                'address' => 'required|string',
                'status'  => 'required|in:active,closed',
                'type'    => 'required|in:business,manufacturer',
                'remarks' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $est->update([
                'name'    => $request->name,
                'address' => $request->address,
                'status'  => $request->status,
                'type'    => $request->type,
                'remarks' => $request->remarks ?? null,
            ]);

            return $this->success('Data saved successfully', $est, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment update failed');
        }
    }

    /**
     * DELETE
     * DELETE /establishment/delete/{establishment_id}
     */
    public function delete($establishment_id)
    {
        try {
            $est = EstablishmentModel::where('establishment_id', (string)$establishment_id)->first();
            if (!$est) {
                return $this->error('Establishment not found.', 404);
            }

            // Validation: Check if there are any receipts for this establishment
            $receiptsCount = ReceiptModel::where('establishment_id', $est->establishment_id)
                ->count();

            if ($receiptsCount > 0) {
                return $this->error("Cannot delete establishment. There are {$receiptsCount} receipt(s) associated with this establishment.", 409);
            }

            DB::beginTransaction();

            try {
                // Delete establishment_sabeel entries first
                EstablishmentSabeelModel::where('establishment_id', $est->establishment_id)->delete();

                // Delete establishment record
            $est->delete();

                DB::commit();

                return $this->success('Establishment deleted successfully', [], 200);

            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment delete failed');
        }
    }

    public function fetch_establishment_details($establishment_id, $id = null)
    {
        try {
            // 1) establishment_id param is establishment_id (10 digit)
            $est = EstablishmentModel::where('establishment_id', $establishment_id)->first();
            if (!$est) {
                return $this->error('Establishment not found.', 404);
            }

            // 2) Decide "current year" for calculation
            //    If {id} passed => use that sabeel entry's year as current
            $focusYear = null;

            if ($id !== null) {
                $entry = EstablishmentSabeelModel::where('id', $id)
                    ->where('establishment_id', $est->establishment_id) // note: stores establishment_id
                    ->first();

                if (!$entry) {
                    return $this->error('Sabeel entry not found for this establishment.', 404);
                }

                $focusYear = $entry->year; // Year is now a string
            }

            [$currentYear, $prevYear, $yearsList] = $this->resolveYearsForOverview($focusYear);

            // 3) Partners list (HOF) from links
            $links = MumineenEstablishmentModel::where('establishment_id', $est->establishment_id)->get();
            $familyIds = $links->pluck('family_id')->filter()->unique()->values()->all();

            $hofs = empty($familyIds)
                ? collect()
                : MumineenModel::whereIn('family_id', $familyIds)
                    ->where('hof_type', 'HOF')
                    ->get()
                    ->keyBy('family_id');

            $partners = [];
            foreach ($links as $lnk) {
                $hof = $hofs->get($lnk->family_id);
                if (!$hof) continue;

                $partners[] = [
                    // 'url'    => "https://talabulilm.com/mumin_images/{$hof->its}.png",
                    'url'    => $hof->pic,
                    'name'   => (string) ($hof->name ?? ''),
                    'its'    => (string) ($hof->its ?? ''),
                    'sector' => (string) ($hof->sector ?? ''),
                    'mobile' => (string) ($hof->mobile ?? ''),
                ];
            }

            // top-level url = first partner image (since establishment has no its)
            $topUrl = count($partners) ? $partners[0]['url'] : '';

            // 4) Preload sabeel by year
            $sabeelByYear = EstablishmentSabeelModel::where('establishment_id', $est->establishment_id)
                ->get()
                ->keyBy('year');

            // 5) Paid receipts by year
            $paidByYear = ReceiptModel::select('year', DB::raw('SUM(amount) as paid'))
                ->where('establishment_id', $est->establishment_id)
                ->where('status', 'active')
                ->groupBy('year')
                ->pluck('paid', 'year');

            // 6) sabeel_details year wise
            $sabeelDetails = [];
            $curSabeel = 0; $curDue = 0; $prevDue = 0;

            foreach ($yearsList as $yr) {
                $sabeelEntry = $sabeelByYear->get($yr);
                $sabeelAmt = $sabeelEntry ? (int) $sabeelEntry->sabeel : 0;
                $paid      = (float) ($paidByYear[$yr] ?? 0);
                $due       = max(0, $sabeelAmt - $paid);

                $sabeelDetails[] = [
                    'year'   => $yr, // Year is already a string like "2025-26"
                    'sabeel' => (string) $sabeelAmt,
                    'due'    => (string) $due,
                ];

                if ($yr === $currentYear) { $curSabeel = $sabeelAmt; $curDue = $due; }
                if ($yr === $prevYear)    { $prevDue  = $due; }
            }

            // 7) Final response object
            $data = [
                'id'               => (string) $est->id,
                'establishment_id' => (string) $est->establishment_id,
                'url'              => (string) $topUrl,

                'name'             => (string) ($est->name ?? ''),
                'address'          => (string) ($est->address ?? ''),

                'establishment' => [
                    'sabeel'   => (string) $curSabeel,
                    'due'      => (string) $curDue,
                    'prev_due' => (string) $prevDue,
                ],

                'sabeel_details' => $sabeelDetails,
                'partners'       => $partners,
            ];

            return $this->success('Data fetched successfully', ['data' => $data], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment overview fetch failed');
        }
    }

    /* ---------------- helpers for overview ---------------- */

    private function resolveYearsForOverview(?string $focusYear = null): array
    {
        // If focusYear passed, use it as current
        if ($focusYear) {
            $current = $focusYear;
            // Get previous year from database
            $currentYearRecord = YearModel::where('year', $current)->first();
            $prevYearRecord = YearModel::where('year', '<', $current)->orderBy('year', 'desc')->first();
            $prev = $prevYearRecord ? $prevYearRecord->year : '';
            
            // Get top 3 years including focus year
            $years = YearModel::orderBy('year', 'desc')->pluck('year')->take(3)->toArray();
            if (count($years) < 3) {
                $allYears = YearModel::orderBy('year', 'desc')->pluck('year')->toArray();
                $years = array_slice($allYears, 0, 3);
            }
            if (!in_array($current, $years)) {
                array_unshift($years, $current);
                $years = array_slice($years, 0, 3);
            }
            
            return [$current, $prev, $years];
        }

        // Else use t_year (if exists), fallback to system year
        if (!Schema::hasTable('t_year')) {
            $currentYearInt = (int) date('Y');
            $current = (string) $currentYearInt;
            $prev = (string) ($currentYearInt - 1);
            $prev2 = (string) ($currentYearInt - 2);
            return [$current, $prev, [$current, $prev, $prev2]];
        }

        $currentYearRecord = YearModel::where('is_current', 1)->first();
        if (!$currentYearRecord) {
            $currentYearRecord = YearModel::orderBy('year', 'desc')->first();
        }
        $current = $currentYearRecord ? $currentYearRecord->year : (string) date('Y');

        // Get previous year
        $prevYearRecord = YearModel::where('year', '<', $current)->orderBy('year', 'desc')->first();
        $prev = $prevYearRecord ? $prevYearRecord->year : '';

        // take top 3 years from table
        $years = YearModel::orderBy('year', 'desc')->pluck('year')->take(3)->toArray();
        if (count($years) < 3) {
            $allYears = YearModel::orderBy('year', 'desc')->pluck('year')->toArray();
            $years = array_slice($allYears, 0, 3);
        }

        return [$current, $prev, $years];
    }

    /* ---------------- Helpers ---------------- */

    private function generateUniqueEstablishmentNo(): string
    {
        do {
            $no = (string) random_int(1000000000, 9999999999);
        } while (EstablishmentModel::where('establishment_id', $no)->exists());

        return $no;
    }

    private function resolveYears(): array
    {
        // safe fallback if t_year missing
        if (!Schema::hasTable('t_year')) {
            $cur = (string) date('Y');
            $prev = (string) (date('Y') - 1);
            return [$cur, $prev];
        }

        $currentYearRecord = YearModel::where('is_current', 1)->first();
        if (!$currentYearRecord) {
            $currentYearRecord = YearModel::orderBy('year', 'desc')->first();
        }
        $cur = $currentYearRecord ? $currentYearRecord->year : (string) date('Y');

        $prevYearRecord = YearModel::where('year', '<', $cur)->orderBy('year', 'desc')->first();
        $prev = $prevYearRecord ? $prevYearRecord->year : '';

        return [$cur, $prev];
    }

    private function applyFilter($q, string $filter, string $currentYear, string $prevYear)
    {
        // manufacturer
        if ($filter === 'manufacturer') {
            return $q->where('type', 'manufacturer');
        }

        // not_tagged => no partners linked
        if ($filter === 'not_tagged') {
            return $q->whereNotIn('establishment_id', function($sub){
                $sub->from('t_mumineen_establishment')->select('establishment_id');
            });
        }

        // new_takhmeen_pending => no establishment_sabeel entry for current year
        if ($filter === 'new_takhmeen_pending') {
            return $q->whereNotIn('id', function($sub) use ($currentYear) {
                $sub->from('t_establishment_sabeel')
                    ->select('establishment_id')
                    ->where('year', $currentYear);
            });
        }

        // due / prev_due => (sabeel - paid_receipts) > 0
        if ($filter === 'due' || $filter === 'prev_due') {
            $year = ($filter === 'due') ? $currentYear : $prevYear;

            return $q->whereIn('id', function($sub) use ($year) {
                $sub->from('t_establishment_sabeel as es')
                    ->select('es.establishment_id')
                    ->leftJoin('t_receipts as r', function($j) use ($year) {
                        $j->on('r.establishment_id','=','es.establishment_id')
                          ->where('r.year','=',$year)
                          ->where('r.status','=','active');
                    })
                    ->where('es.year', $year)
                    ->groupBy('es.establishment_id','es.sabeel')
                    ->havingRaw('(es.sabeel - COALESCE(SUM(r.amount),0)) > 0');
            });
        }

        return $q;
    }

    private function buildPayload($rows, string $currentYear, string $prevYear): array
    {
        $estIds = collect($rows)
            ->pluck('establishment_id')   // ✅ 10-digit business key
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($estIds)) return [];

        // Establishment sabeel entries
        $es = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
            ->get()
            ->groupBy('establishment_id');

        // Receipts paid by year for establishments
        $paid = ReceiptModel::select('establishment_id','year', DB::raw('SUM(amount) as paid'))
            ->whereIn('establishment_id', $estIds)
            ->where('status','active')
            ->groupBy('establishment_id','year')
            ->get()
            ->groupBy('establishment_id');

        // Partners via links (family_id) => get HOF data from t_mumineen
        $links = MumineenEstablishmentModel::whereIn('establishment_id', $estIds)
            ->get()
            ->groupBy('establishment_id');

        $familyIds = $links->flatten()->pluck('family_id')->filter()->unique()->values()->all();

        $hofByFamily = MumineenModel::whereIn('family_id', $familyIds)
            ->where('hof_type', 'HOF')
            ->get()
            ->keyBy('family_id');

        $out = [];

        foreach ($rows as $e) {
            // Use establishment_id (business key) for lookups
            $estId = (string) $e->establishment_id;

            // Current year sabeel and due
            $curSabeel = (int) optional($es->get($estId))?->firstWhere('year', $currentYear)?->sabeel ?? 0;
            $curPaid  = (float) optional($paid->get($estId))?->firstWhere('year', $currentYear)?->paid ?? 0;
            $curDue  = max(0, $curSabeel - $curPaid);

            // Calculate prev_due as sum of dues for all years before current year
            $prevDue = $this->establishmentTotalDueForAllPreviousYears([$estId], $currentYear);

            // Build partners list
            $partners = [];
            $estLinks = $links->get($e->establishment_id) ?? collect();

            foreach ($estLinks as $lnk) {
                $hof = $hofByFamily->get($lnk->family_id);
                if (!$hof) continue;

                $partners[] = [
                    // 'url'    => "https://talabulilm.com/mumin_images/{$hof->its}.png",
                    'url'    => $hof->pic,
                    'name'   => (string) ($hof->name ?? ''),
                    'its'    => (string) ($hof->its ?? ''),
                    'sector' => (string) ($hof->sector ?? ''),
                    'mobile' => (string) ($hof->mobile ?? ''),
                ];
            }

            $out[] = [
                'id'               => (string) $e->id,
                'establishment_id' => (string) $e->establishment_id,
                'name'             => (string) $e->name,
                'address'          => (string) $e->address,

                'establishment' => [
                    'sabeel'   => (string) $curSabeel,
                    'due'      => (string) $curDue,
                    'prev_due' => (string) $prevDue,
                ],

                'partners' => $partners,
            ];
        }

        return $out;
    }

    /**
     * Calculate total establishment due for all years before current year (for prev_due)
     */
    private function establishmentTotalDueForAllPreviousYears(array $estIds, string $currentYear): float
    {
        if (empty($estIds)) return 0;

        // Get all sabeel entries for years < current year
        $sabeelEntries = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
            ->where('year', '<', $currentYear)
            ->get()
            ->groupBy('year');

        $totalPrevDue = 0;

        foreach ($sabeelEntries as $year => $entries) {
            $sabeelSum = (float) $entries->sum('sabeel');
            $paidSum = (float) ReceiptModel::whereIn('establishment_id', $estIds)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');
            $due = max(0, $sabeelSum - $paidSum);
            $totalPrevDue += $due;
        }

        return $totalPrevDue;
    }
}
