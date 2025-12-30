<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
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
    use ApiResponse;

    /**
     * CREATE
     * POST /establishment/create
     * Auto-generate establishment_no (10 digit unique)
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
                'establishment_no' => $estNo,
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
                ->select('id','establishment_no','name','address','type','status')
                ->orderBy('id','desc');

            // SEARCH: in establishment name/address/establishment_no AND partner mumineen (its/name/sector) AND family member its
            if ($search !== '') {
                $q->where(function($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhere('address', 'like', "%{$search}%")
                      ->orWhere('establishment_no', 'like', "%{$search}%")
                      ->orWhereExists(function($sub) use ($search) {
                          $sub->from('t_mumineen_establishment as me')
                              ->join('t_mumineen as m', 'm.family_id', '=', 'me.family_id')
                              ->whereColumn('me.establishment_no', 't_establishment.id')
                              ->where(function($x) use ($search) {
                                  $x->where('m.its', 'like', "%{$search}%")
                                    ->orWhere('m.name', 'like', "%{$search}%")
                                    ->orWhere('m.sector', 'like', "%{$search}%");
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
    public function edit(Request $request, $id)
    {
        try {
            $est = EstablishmentModel::find($id);
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
     * DELETE /establishment/delete/{id}
     */
    public function delete($id)
    {
        try {
            $est = EstablishmentModel::find($id);
            if (!$est) return $this->error('Establishment not found.', 404);

            // Optional safety: prevent delete if linked
            // if ($est->mumineenLinks()->exists()) return $this->error('Cannot delete. Establishment has partners linked.', 409);

            $est->delete();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment delete failed');
        }
    }

    /* ---------------- Helpers ---------------- */

    private function generateUniqueEstablishmentNo(): string
    {
        do {
            $no = (string) random_int(1000000000, 9999999999);
        } while (EstablishmentModel::where('establishment_no', $no)->exists());

        return $no;
    }

    private function resolveYears(): array
    {
        // safe fallback if t_year missing
        if (!Schema::hasTable('t_year')) {
            $cur = (int) date('Y');
            return [$cur, $cur - 1];
        }

        $cur = (int) YearModel::where('is_current', 1)->value('year');
        if (!$cur) $cur = (int) YearModel::max('year');
        if (!$cur) $cur = (int) date('Y');

        $prev = (int) YearModel::where('year', '<', $cur)->max('year');
        if (!$prev) $prev = $cur - 1;

        return [$cur, $prev];
    }

    private function applyFilter($q, string $filter, int $currentYear, int $prevYear)
    {
        // manufacturer
        if ($filter === 'manufacturer') {
            return $q->where('type', 'manufacturer');
        }

        // not_tagged => no partners linked
        if ($filter === 'not_tagged') {
            return $q->whereNotIn('id', function($sub){
                $sub->from('t_mumineen_establishment')->select('establishment_no');
            });
        }

        // new_takhmeen_pending => no establishment_sabeel entry for current year
        if ($filter === 'new_takhmeen_pending') {
            return $q->whereNotIn('id', function($sub) use ($currentYear) {
                $sub->from('t_establishment_sabeel')
                    ->select('establishment_no')
                    ->where('year', $currentYear);
            });
        }

        // due / prev_due => (sabeel - paid_receipts) > 0
        if ($filter === 'due' || $filter === 'prev_due') {
            $year = ($filter === 'due') ? $currentYear : $prevYear;

            return $q->whereIn('id', function($sub) use ($year) {
                $sub->from('t_establishment_sabeel as es')
                    ->select('es.establishment_no')
                    ->leftJoin('t_receipts as r', function($j) use ($year) {
                        $j->on('r.establishment_no','=','es.establishment_no')
                          ->where('r.year','=',$year)
                          ->where('r.status','=','active');
                    })
                    ->where('es.year', $year)
                    ->groupBy('es.establishment_no','es.sabeel')
                    ->havingRaw('(es.sabeel - COALESCE(SUM(r.amount),0)) > 0');
            });
        }

        return $q;
    }

    private function buildPayload($rows, int $currentYear, int $prevYear): array
    {
        $estIds = collect($rows)->pluck('id')->filter()->unique()->values()->all();
        if (empty($estIds)) return [];

        // Establishment sabeel entries
        $es = EstablishmentSabeelModel::whereIn('establishment_no', $estIds)
            ->get()
            ->groupBy('establishment_no');

        // Receipts paid by year for establishments
        $paid = ReceiptModel::select('establishment_no','year', DB::raw('SUM(amount) as paid'))
            ->whereIn('establishment_no', $estIds)
            ->where('status','active')
            ->groupBy('establishment_no','year')
            ->get()
            ->groupBy('establishment_no');

        // Partners via links (family_id) => get HOF data from t_mumineen
        $links = MumineenEstablishmentModel::whereIn('establishment_no', $estIds)
            ->get()
            ->groupBy('establishment_no');

        $familyIds = $links->flatten()->pluck('family_id')->filter()->unique()->values()->all();

        $hofByFamily = MumineenModel::whereIn('family_id', $familyIds)
            ->where('hof_type', 'HOF')
            ->get()
            ->keyBy('family_id');

        $out = [];

        foreach ($rows as $e) {

            $curSabeel = (int) optional($es->get($e->id))?->firstWhere('year', $currentYear)?->sabeel ?? 0;
            $prevSabeel = (int) optional($es->get($e->id))?->firstWhere('year', $prevYear)?->sabeel ?? 0;

            $curPaid  = (float) optional($paid->get($e->id))?->firstWhere('year', $currentYear)?->paid ?? 0;
            $prevPaid = (float) optional($paid->get($e->id))?->firstWhere('year', $prevYear)?->paid ?? 0;

            $curDue  = max(0, $curSabeel - $curPaid);
            $prevDue = max(0, $prevSabeel - $prevPaid);

            // Build partners list
            $partners = [];
            $estLinks = $links->get($e->id) ?? collect();

            foreach ($estLinks as $lnk) {
                $hof = $hofByFamily->get($lnk->family_id);
                if (!$hof) continue;

                $partners[] = [
                    'url'    => "https://talabulilm.com/mumin_images/{$hof->its}.png",
                    'name'   => (string) ($hof->name ?? ''),
                    'its'    => (string) ($hof->its ?? ''),
                    'sector' => (string) ($hof->sector ?? ''),
                    'mobile' => (string) ($hof->mobile ?? ''),
                ];
            }

            $out[] = [
                'id'               => (string) $e->id,
                'establishment_id' => (string) $e->establishment_no,
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
}
