<?php

namespace App\Http\Controllers;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExcelExport;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use App\Helpers\ExcelExportHelper;
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;
use Illuminate\Support\Facades\Schema;

class MumineenController extends Controller
{
    //
    use ApiResponse;
    /**
     * Create HOF Mumineen
     * Defaults:
     * family_id = auto 10 digit unique
     * hof_type = HOF
     * hof_its = NULL
     * family_its = NULL
     * age = NULL
     * status = active
     */
    public function create(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'its'        => 'required|string|max:255|unique:t_mumineen,its',
                'name'       => 'required|string|max:255',
                'gender'     => 'required|in:male,female',

                'sector'     => 'nullable|string|max:255',
                'sub_sector' => 'nullable|string|max:255',
                'mobile'     => 'nullable|string|max:255',
                'email'      => 'nullable|email|max:255',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $familyId = $this->generateUniqueFamilyId();

            $row = MumineenModel::create([
                'family_id'  => $familyId,
                'hof_type'   => 'HOF',

                'its'        => $request->its,
                'hof_its'    => null,
                'family_its' => null,

                'name'       => $request->name,
                'sector'     => $request->sector ?? null,
                'sub_sector' => $request->sub_sector ?? null,

                'mobile'     => $request->mobile ?? null,
                'email'      => $request->email ?? null,

                'gender'     => $request->gender,
                'age'        => null,

                'status'     => 'active',
            ]);

            return $this->success('Data saved successfully', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen create failed');
        }
    }

    /**
     * Fetch list/single
     * POST body:
     * {
     *   "search": "",
     *   "sector": "",
     *   "filter": "due|prev_due|new_takhmeen_pending|not_tagged|service",
     *   "limit": 10,
     *   "offset": 0
     * }
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            [$currentYear, $prevYear, $yearsList] = $this->resolveYears();

            // SINGLE -> still return array (consistent with your sample)
            if ($id !== null) {
                $m = MumineenModel::where('hof_type','HOF')
                    ->where(function ($q) use ($id) {
                        $q->where('id', $id)
                        ->orWhere('family_id', $id);
                    })
                    ->first();

                if (!$m) {
                    return $this->error('Mumineen not found.', 404);
                }

                $data = $this->buildPayload(collect([$m]), $currentYear, $prevYear, $yearsList);

                return $this->success('Data fetched successfully', $data, 200);
            }

            $limit  = max(1, (int) $request->input('limit', 10));
            $offset = max(0, (int) $request->input('offset', 0));
            $search = trim((string) $request->input('search', ''));
            $sector = trim((string) $request->input('sector', ''));
            $filter = trim((string) $request->input('filter', ''));

            $q = MumineenModel::query()
                ->where('hof_type', 'HOF')
                ->select('id','family_id','its','name','sector','mobile','email')
                ->orderBy('id', 'desc');

            // search in name, its, sector, and also family member its numbers
            if ($search !== '') {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhere('its', 'like', "%{$search}%")
                      ->orWhere('sector', 'like', "%{$search}%")
                      ->orWhereIn('family_id', function($sub) use ($search) {
                          $sub->from('t_mumineen')
                              ->select('family_id')
                              ->where('its', 'like', "%{$search}%");
                      });
                });
            }

            if ($sector !== '') {
                $q->where('sector', $sector);
            }

            if ($filter !== '') {
                $q = $this->applyFilter($q, $filter, $currentYear, $prevYear);
            }

            $total = (clone $q)->count();

            $rows = $q->skip($offset)->take($limit)->get();

            $data = $this->buildPayload($rows, $currentYear, $prevYear, $yearsList);

            return $this->success('Data fetched successfully', $data, 200, [
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'count'  => count($data),
                    'total'  => $total,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen fetch failed');
        }
    }

    public function edit(Request $request, $family_id)
    {
        try {
            $m = MumineenModel::where('hof_type', 'HOF')
                ->where('family_id', (int) $family_id)
                ->first();

            if (!$m) {
                return $this->error('Mumineen not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'its'        => 'required|string|max:255|unique:t_mumineen,its,' . $m->id,
                'name'       => 'required|string|max:255',
                'gender'     => 'required|in:male,female',

                'sector'     => 'nullable|string|max:255',
                'sub_sector' => 'nullable|string|max:255',
                'mobile'     => 'nullable|string|max:255',
                'email'      => 'nullable|email|max:255',

                // optional if you want to allow status change:
                // 'status'  => 'nullable|in:active,closed',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $m->update([
                'its'        => $request->its,
                'name'       => $request->name,
                'gender'     => $request->gender,

                'sector'     => $request->sector ?? null,
                'sub_sector' => $request->sub_sector ?? null,
                'mobile'     => $request->mobile ?? null,
                'email'      => $request->email ?? null,

                // 'status'  => $request->status ?? $m->status,
            ]);

            return $this->success('Data saved successfully', $m, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen update failed');
        }
    }

    public function delete($id)
    {
        try {
            $m = MumineenModel::where('hof_type', 'HOF')->find($id);

            if (!$m) {
                return $this->error('Mumineen not found.', 404);
            }

            $m->status = 'closed';
            $m->save();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen delete failed');
        }
    }

    public function fetch_family_details($family_id, $id = null)
    {
        try {
            // 1) Resolve familyId + HOF row
            if ($id !== null) {
                // If id passed, treat it as t_mumineen.id
                $hof = MumineenModel::where('hof_type', 'HOF')->find($id);
                if (!$hof) return $this->error('Mumineen not found.', 404);

                $familyId = (int) $hof->family_id;
            } else {
                // Else treat param as 10-digit family_id
                $familyId = (int) $family_id;

                $hof = MumineenModel::where('family_id', $familyId)
                    ->where('hof_type', 'HOF')
                    ->first();

                if (!$hof) return $this->error('Family not found.', 404);
            }

            // 2) Resolve years
            [$currentYear, $prevYear] = array_slice($this->resolveYears(), 0, 2);

            // 3) FAMILY sabeel + due
            [$famCurSabeel, $famCurDue]   = $this->familyDueForYear($familyId, $currentYear);
            [$famPrevSabeel, $famPrevDue] = $this->familyDueForYear($familyId, $prevYear);

            // 4) Establishment totals (all linked establishments) - use business codes
            $estCodes = MumineenEstablishmentModel::where('family_id', $familyId)
                ->pluck('establishment_id')     // ✅ 10-digit business codes
                ->filter()
                ->unique()
                ->values()
                ->all();

            // Now calculate totals using business codes (NO conversion to pk)
            [$estCurSabeel, $estCurDue]   = $this->establishmentTotalsDueForYear($estCodes, $currentYear);
            [$estPrevSabeel, $estPrevDue] = $this->establishmentTotalsDueForYear($estCodes, $prevYear);

            // 5) Response payload (as you required)
            $data = [
                'id'        => (string) $hof->id,
                'family_id' => (string) $familyId,
                'url'       => "https://talabulilm.com/mumin_images/{$hof->its}.png",

                'name'   => (string) ($hof->name ?? ''),
                'its'    => (string) ($hof->its ?? ''),
                'sector' => (string) ($hof->sector ?? ''),
                'mobile' => (string) ($hof->mobile ?? ''),
                'email'  => (string) ($hof->email ?? ''),

                'sabeel' => [
                    'sabeel'   => (string) $famCurSabeel,
                    'due'      => (string) $famCurDue,
                    'prev_due' => (string) $famPrevDue,
                ],

                'establishment' => [
                    'sabeel'   => (string) $estCurSabeel,
                    'due'      => (string) $estCurDue,
                    'prev_due' => (string) $estPrevDue,
                ],
            ];

            // Your ApiResponse -> returns {code,status,message,data:{...}}
            return $this->success('Data fetched successfully', ['data' => $data], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Family overview fetch failed');
        }
    }

    // export
    public function export(Request $request)
    {
        try {
            [$currentYear, $prevYear, $yearsList] = $this->resolveYears();

            $limit  = max(1, (int) $request->input('limit', 50));
            $offset = max(0, (int) $request->input('offset', 0));

            $q = MumineenModel::where('hof_type','HOF')
                ->orderBy('name','asc');

            if ($request->filled('search')) {
                $q->where('name','like',"%{$request->search}%");
            }

            if ($request->filled('sector')) {
                $q->where('sector',$request->sector);
            }

            if ($request->filled('filter')) {
                $q = $this->applyFilter($q,$request->filter,$currentYear,$prevYear);
            }

            $rows = $this->buildPayload(
                $q->skip($offset)->take($limit)->get(),
                $currentYear,
                $prevYear,
                $yearsList
            );

            $excelRows = [];
            $sn = $offset + 1;

            foreach ($rows as $r) {
                $excelRows[] = [
                    $sn++,
                    $r['its'],
                    $r['name'],
                    $r['mobile'],
                    $r['email'],
                    $r['sector'],
                    (float) $r['sabeel']['sabeel'],
                ];
            }

            $export = new GenericExcelExport(
                $excelRows,
                ['SN','ITS','Name','Mobile','Email','Sector','Sabeel'],
                [
                    'G' => '_₹* #,##0.00_ ;_₹* (#,##0.00);_₹* "-"??_ ;_@_ '
                ],
                [
                    'A' => Alignment::HORIZONTAL_CENTER,
                    'B' => Alignment::HORIZONTAL_CENTER,
                    'C' => Alignment::HORIZONTAL_LEFT,
                    'D' => Alignment::HORIZONTAL_CENTER,
                    'E' => Alignment::HORIZONTAL_LEFT,
                    'F' => Alignment::HORIZONTAL_CENTER,
                    'G' => Alignment::HORIZONTAL_RIGHT,
                ]
            );

            return ExcelExportHelper::store($export,'family','family_export');

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Family export failed');
        }
    }

    /* ---------------- helpers for overview ---------------- */

    private function resolveYearsSimple(): array
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

    private function familyDueForYear(int $familyId, int $year): array
    {
        $sabeel = (int) MumineenSabeelModel::where('family_id', $familyId)
            ->where('year', $year)
            ->value('sabeel');

        $paid = (float) ReceiptModel::where('family_id', $familyId)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');

        $due = max(0, $sabeel - $paid);

        return [$sabeel, $due];
    }

    private function establishmentTotalsDueForYear(array $estCodes, int $year): array
    {
        if (empty($estCodes)) return [0, 0];

        $sabeelSum = (int) EstablishmentSabeelModel::whereIn('establishment_id', $estCodes)
            ->where('year', $year)
            ->sum('sabeel');

        $paidSum = (float) ReceiptModel::whereIn('establishment_id', $estCodes)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');

        $dueSum = max(0, $sabeelSum - $paidSum);

        return [$sabeelSum, $dueSum];
    }

    // sector index
    public function index(Request $request)
    {
        try {
            $sectors = DB::table('v_sectors')
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

            return $this->success('Sectors fetched successfully', $sectors, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Sectors fetch failed');
        }
    }

    /* ----------------- Helpers ----------------- */

    private function generateUniqueFamilyId(): int
    {
        do {
            $familyId = random_int(1000000000, 9999999999);
        } while (MumineenModel::where('family_id', $familyId)->exists());

        return $familyId;
    }

    private function resolveYears(): array
    {
        $currentYear = (int) YearModel::where('is_current', 1)->value('year');
        if (!$currentYear) {
            $currentYear = (int) YearModel::max('year');
        }
        if (!$currentYear) {
            $currentYear = (int) date('Y');
        }

        $yearsList = YearModel::orderBy('year','desc')->pluck('year')->toArray();
        if (empty($yearsList)) {
            $yearsList = [$currentYear, $currentYear - 1, $currentYear - 2];
        }

        $prevYear = collect($yearsList)->filter(fn($y) => $y < $currentYear)->first();
        $prevYear = $prevYear ? (int)$prevYear : ($currentYear - 1);

        return [$currentYear, $prevYear, $yearsList];
    }

    private function applyFilter($q, string $filter, int $currentYear, int $prevYear)
    {
        if ($filter === 'not_tagged') {
            return $q->whereNotIn('family_id', function($sub){
                $sub->from('t_mumineen_establishment')->select('family_id');
            });
        }

        if ($filter === 'service') {
            return $q->whereIn('family_id', function($sub){
                $sub->from('t_mumineen_establishment')->select('family_id');
            });
        }

        if ($filter === 'new_takhmeen_pending') {
            return $q->whereNotIn('family_id', function($sub) use ($currentYear){
                $sub->from('t_mumineen_sabeel')
                    ->select('family_id')
                    ->where('year', $currentYear);
            });
        }

        if ($filter === 'due' || $filter === 'prev_due') {
            $year = ($filter === 'due') ? $currentYear : $prevYear;

            return $q->whereIn('family_id', function($sub) use ($year) {
                $sub->from('t_mumineen_sabeel as ms')
                    ->select('ms.family_id')
                    ->leftJoin('t_receipts as r', function($j) use ($year) {
                        $j->on('r.family_id','=','ms.family_id')
                          ->where('r.year','=',$year)
                          ->where('r.status','=','active');
                    })
                    ->where('ms.year', $year)
                    ->groupBy('ms.family_id','ms.sabeel')
                    ->havingRaw('(ms.sabeel - COALESCE(SUM(r.amount),0)) > 0');
            });
        }

        return $q;
    }

    private function buildPayload($rows, int $currentYear, int $prevYear, array $yearsList): array
    {
        $familyIds = collect($rows)->pluck('family_id')->filter()->unique()->values()->all();
        if (empty($familyIds)) return [];

        // Family sabeel entries
        $ms = MumineenSabeelModel::whereIn('family_id', $familyIds)
            ->get()
            ->groupBy('family_id');

        // Family receipts paid by year
        $familyPaid = ReceiptModel::select('family_id','year', DB::raw('SUM(amount) as paid'))
            ->whereIn('family_id', $familyIds)
            ->where('status','active')
            ->groupBy('family_id','year')
            ->get()
            ->groupBy('family_id');

        // Establishment links + names
        $links = MumineenEstablishmentModel::with('establishment')
            ->whereIn('family_id', $familyIds)
            ->get()
            ->groupBy('family_id');

        $estIds = $links->flatten()->pluck('establishment_id')->filter()->unique()->values()->all();

        // Establishment sabeel
        $es = empty($estIds) ? collect() : EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
            ->get()
            ->groupBy('establishment_id');

        // Establishment receipts paid by year
        $estPaid = empty($estIds) ? collect() : ReceiptModel::select('establishment_id','year', DB::raw('SUM(amount) as paid'))
            ->whereIn('establishment_id', $estIds)
            ->where('status','active')
            ->groupBy('establishment_id','year')
            ->get()
            ->groupBy('establishment_id');

        $out = [];

        foreach ($rows as $m) {

            // sabeel_details year wise
            $sabeelDetails = [];
            $curSabeel = 0; $curDue = 0; $prevDue = 0;

            foreach ($yearsList as $yr) {
                $sabeelAmt = (int) optional($ms->get($m->family_id))
                    ?->firstWhere('year', $yr)
                    ?->sabeel ?? 0;

                $paid = (float) optional($familyPaid->get($m->family_id))
                    ?->firstWhere('year', $yr)
                    ?->paid ?? 0;

                $due = max(0, $sabeelAmt - $paid);

                $sabeelDetails[] = [
                    'year'   => $this->yearLabel((int)$yr),
                    'sabeel' => (string)$sabeelAmt,
                    'due'    => (string)$due,
                ];

                if ((int)$yr === $currentYear) { $curSabeel = $sabeelAmt; $curDue = $due; }
                if ((int)$yr === $prevYear) { $prevDue = $due; }
            }

            // establishment_details
            $estDetails = [];
            $estCurSabeelSum = 0; $estCurDueSum = 0; $estPrevDueSum = 0;

            $familyLinks = $links->get($m->family_id) ?? collect();

            foreach ($familyLinks->unique('establishment_id') as $lnk) {
                $estId = (string) $lnk->establishment_id;
                $estName = (string) (optional($lnk->establishment)->name ?? '');

                $estSabeelCur = (int) optional($es->get($estId))
                    ?->firstWhere('year', $currentYear)
                    ?->sabeel ?? 0;

                $estPaidCur = (float) optional($estPaid->get($estId))
                    ?->firstWhere('year', $currentYear)
                    ?->paid ?? 0;

                $estDueCur = max(0, $estSabeelCur - $estPaidCur);

                $estSabeelPrev = (int) optional($es->get($estId))
                    ?->firstWhere('year', $prevYear)
                    ?->sabeel ?? 0;

                $estPaidPrev = (float) optional($estPaid->get($estId))
                    ?->firstWhere('year', $prevYear)
                    ?->paid ?? 0;

                $estDuePrev = max(0, $estSabeelPrev - $estPaidPrev);

                $estDetails[] = [
                    'establishment_id' => $estId,
                    'name'             => $estName,
                    'due'              => (string)$estDueCur,
                ];

                $estCurSabeelSum += $estSabeelCur;
                $estCurDueSum    += $estDueCur;
                $estPrevDueSum   += $estDuePrev;
            }

            $out[] = [
                'id'        => (string) $m->id,
                'family_id' => (string) $m->family_id,
                'url'       => "https://talabulilm.com/mumin_images/{$m->its}.png",

                'name'      => (string) $m->name,
                'its'       => (string) $m->its,
                'sector'    => (string) ($m->sector ?? ''),
                'mobile'    => (string) ($m->mobile ?? ''),
                'email'     => (string) ($m->email ?? ''),

                'sabeel' => [
                    'sabeel'   => (string) $curSabeel,
                    'due'      => (string) $curDue,
                    'prev_due' => (string) $prevDue,
                ],

                'establishment' => [
                    'sabeel'   => (string) $estCurSabeelSum,
                    'due'      => (string) $estCurDueSum,
                    'prev_due' => (string) $estPrevDueSum,
                ],

                'sabeel_details' => $sabeelDetails,
                'establishment_details' => $estDetails,
            ];
        }

        return $out;
    }

    private function yearLabel(int $year): string
    {
        $next = substr((string)($year + 1), -2);
        return "{$year}-{$next}";
    }
}
