<?php

namespace App\Http\Controllers;
use App\Traits\ApiResponse;
use App\Traits\SmartSearch;
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
use App\Models\AdvancePaidModel;
use App\Services\DueCalculationService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;

class MumineenController extends Controller
{
    //
    use ApiResponse, SmartSearch;
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
                'its'              => 'required|string|max:255',
                'name'             => 'required|string|max:255',
                'gender'           => 'required|in:male,female',
                'sector'           => 'nullable|string|max:255',
                'sub_sector'       => 'nullable|string|max:255',
                'mobile'           => 'nullable|string|max:255',
                'email'            => 'nullable|email|max:255',
                'promote_fm_to_hof'=> 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $its = trim((string) $request->its);
            $promoteFmToHof = $request->boolean('promote_fm_to_hof');

            if ($promoteFmToHof) {
                return $this->promoteFmToHof($request, $its);
            }

            $existing = MumineenModel::where('its', $its)->first();
            if ($existing) {
                if (strtoupper((string) $existing->hof_type) === 'HOF') {
                    return $this->error('ITS is already registered as Head of Family.', 422);
                }
                if (strtoupper((string) $existing->hof_type) === 'FM') {
                    return $this->error(
                        'This ITS already exists as a family member (FM).',
                        409,
                        [
                            'reason' => 'fm_exists',
                            'data'   => [
                                'its'    => (string) $existing->its,
                                'name'   => (string) ($existing->name ?? ''),
                                'gender' => (string) ($existing->gender ?? ''),
                            ],
                        ]
                    );
                }
                return $this->error('ITS is already registered.', 422);
            }

            $row = $this->createHofRow($request, $its, $this->generateUniqueFamilyId());

            return $this->success('Data saved successfully', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen create failed');
        }
    }

    /**
     * Promote an existing FM row to HOF with a new family_id.
     */
    private function promoteFmToHof(Request $request, string $its)
    {
        $fm = MumineenModel::where('its', $its)
            ->where('hof_type', 'FM')
            ->first();

        if (!$fm) {
            return $this->error('No family member (FM) found with this ITS to promote.', 404);
        }

        if (strtolower((string) $fm->status) === 'closed') {
            return $this->error('This family member is closed and cannot be promoted.', 422);
        }

        $familyId = $this->generateUniqueFamilyId();
        $picUrl = $this->resolveMumineenPicUrl($its);

        $fm->update([
            'family_id'  => $familyId,
            'hof_type'   => 'HOF',
            'hof_its'    => null,
            'family_its' => null,
            'name'       => $request->name,
            'sector'     => $request->sector ?? null,
            'sub_sector' => $request->sub_sector ?? null,
            'mobile'     => $request->mobile ?? null,
            'email'      => $request->email ?? null,
            'gender'     => $request->gender,
            'age'        => null,
            'pic'        => $picUrl,
            'status'     => 'active',
            'external'   => false,
        ]);

        return $this->success('Family member promoted to Head of Family successfully', $fm->fresh(), 200);
    }

    private function createHofRow(Request $request, string $its, int $familyId): MumineenModel
    {
        return MumineenModel::create([
            'family_id'  => $familyId,
            'hof_type'   => 'HOF',
            'its'        => $its,
            'hof_its'    => null,
            'family_its' => null,
            'name'       => $request->name,
            'sector'     => $request->sector ?? null,
            'sub_sector' => $request->sub_sector ?? null,
            'mobile'     => $request->mobile ?? null,
            'email'      => $request->email ?? null,
            'gender'     => $request->gender,
            'age'        => null,
            'pic'        => $this->resolveMumineenPicUrl($its),
            'status'     => 'active',
            'external'   => false,
        ]);
    }

    private function resolveMumineenPicUrl(string $its): string
    {
        $picUrl = url('storage/uploads/its_images/placeholder.jpg');
        $itsImagePath = public_path('storage/uploads/its_images/' . $its . '.jpg');
        if (file_exists($itsImagePath)) {
            $picUrl = url('storage/uploads/its_images/' . $its . '.jpg');
        }
        return $picUrl;
    }

    /**
     * Fetch list/single
     * POST body:
     * {
     *   "search": "",
     *   "sector": "",
     *   "filter": "due|prev_due|new_takhmeen_pending|not_tagged|external",
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
                $selectFields = ['id','family_id','its','name','sector','mobile','email', 'pic'];
                // Add verification fields if columns exist
                if (Schema::hasColumn('t_mumineen', 'is_verified')) {
                    $selectFields[] = 'is_verified';
                }
                if (Schema::hasColumn('t_mumineen', 'is_takhmeen_updated')) {
                    $selectFields[] = 'is_takhmeen_updated';
                }

                $m = MumineenModel::where('hof_type','HOF')
                    ->where('status', 'active')
                    ->where(function ($q) use ($id) {
                        $q->where('id', $id)
                        ->orWhere('family_id', $id);
                    })
                    ->select($selectFields)
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
            $isVerified = $request->input('is_verified');
            $isTakhmeenUpdated = $request->input('is_takhmeen_updated');

            $selectFields = ['id','family_id','its','name','sector','mobile','email', 'pic'];
            // Add verification fields if columns exist
            if (Schema::hasColumn('t_mumineen', 'is_verified')) {
                $selectFields[] = 'is_verified';
            }
            if (Schema::hasColumn('t_mumineen', 'is_takhmeen_updated')) {
                $selectFields[] = 'is_takhmeen_updated';
            }

            $q = MumineenModel::query()
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->whereNotIn('its', ['20320125', '20303586', '30350003'])
                ->select($selectFields)
                ->orderByRaw("CASE 
                    WHEN sector = 'BURHANI' THEN 1 
                    WHEN sector = 'EZZY' THEN 2 
                    WHEN sector = 'MOHAMMEDI' THEN 3 
                    WHEN sector = 'SHUJAI' THEN 4 
                    WHEN sector = 'ZAINY' THEN 5 
                    ELSE 6 
                END")
                ->orderBy('sub_sector', 'asc')
                ->orderBy('name', 'asc');

            // Smart search in name, its, sector, and also family member its numbers
            if ($search !== '') {
                $this->applySmartSearch($q, $search, ['name', 'its', 'sector'], function ($query, $keyword) {
                    $query->orWhereIn('family_id', function($sub) use ($keyword) {
                          $sub->from('t_mumineen')
                              ->select('family_id')
                            ->where('its', 'like', "%{$keyword}%");
                      });
                });
            }

            if ($sector !== '') {
                $q->where('sector', $sector);
            }

            // Filter by is_verified (only if column exists)
            if ($isVerified !== null && Schema::hasColumn('t_mumineen', 'is_verified')) {
                $q->where('is_verified', (bool) $isVerified);
            }

            // Filter by is_takhmeen_updated (only if column exists)
            if ($isTakhmeenUpdated !== null && Schema::hasColumn('t_mumineen', 'is_takhmeen_updated')) {
                $q->where('is_takhmeen_updated', (bool) $isTakhmeenUpdated);
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
            [$currentYear, $prevYear, $yearsList] = $this->resolveYears();

            $dueService = app(DueCalculationService::class);
            $famDue = $dueService->getFamilyDue($familyId, $currentYear);

            // 4) Build sabeel_details (year-wise, raw; current year due overwritten with effective)
            $sabeelDetails = [];
            $yearDueList = $dueService->getFamilyDueByYear($familyId, $yearsList);
            $currentYearLabel = $this->yearLabel((int)$currentYear);
            foreach ($yearDueList as $yd) {
                $dueVal = $yd['due'];
                if ($yd['year'] === $currentYear) {
                    $dueVal = $famDue['due_effective'];
                }
                $sabeelDetails[] = [
                    'year'   => $this->yearLabel((int)$yd['year']),
                    'sabeel' => (string) $yd['sabeel'],
                    'due'    => (string) $dueVal,
                ];
            }

            $estCodes = MumineenEstablishmentModel::where('family_id', $familyId)
                ->pluck('establishment_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $estTotals = $dueService->getEstablishmentTotalsForFamily($estCodes, $currentYear);
            $estDueBulk = empty($estCodes) ? [] : $dueService->getEstablishmentDueBulk($estCodes, $currentYear);

            $establishmentDetails = [];
            $links = MumineenEstablishmentModel::with('establishment')
                ->where('family_id', $familyId)
                ->get()
                ->unique('establishment_id');

            foreach ($links as $lnk) {
                $estId = (string) $lnk->establishment_id;
                $estName = (string) (optional($lnk->establishment)->name ?? '');
                $eDue = $estDueBulk[$estId] ?? null;
                $estSabeelCur = $eDue ? $eDue['sabeel'] : 0;
                $estDueCurEffective = $eDue ? $eDue['due_effective'] : 0;
                $establishmentDetails[] = [
                    'establishment_id' => $estId,
                    'name'             => $estName,
                    'sabeel'           => (string) $estSabeelCur,
                    'due'              => (string) $estDueCurEffective,
                ];
            }

            $data = [
                'id'        => (string) $hof->id,
                'family_id' => (string) $familyId,
                'url'       => (string) $hof->pic,

                'name'   => (string) ($hof->name ?? ''),
                'its'    => (string) ($hof->its ?? ''),
                'sector' => (string) ($hof->sector ?? ''),
                'mobile' => (string) ($hof->mobile ?? ''),
                'email'  => (string) ($hof->email ?? ''),

                'sabeel' => [
                    'sabeel'   => (string) $famDue['sabeel'],
                    'due'      => (string) $famDue['due_effective'],
                    'prev_due' => (string) $famDue['prev_due_effective'],
                ],

                'sabeel_details' => $sabeelDetails,

                'establishment' => [
                    'sabeel'   => (string) $estTotals['sabeel'],
                    'due'      => (string) $estTotals['due_effective'],
                    'prev_due' => (string) $estTotals['prev_due_effective'],
                ],

                'establishment_details' => $establishmentDetails,
            ];

            // Your ApiResponse -> returns {code,status,message,data:{...}}
            return $this->success('Data fetched successfully', ['data' => $data], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Family overview fetch failed');
        }
    }

    /**
     * Fetch all family members (FMs only) for a given family_id
     * GET /family_members/retrieve/{family_id}
     */
    public function fetchFamilyMembers($family_id)
    {
        try {
            $familyId = (int) $family_id;

            // Fetch only FMs (Family Members) for this family_id
            // Order by family_its groups (highest age in group first), then age DESC within each group
            $members = MumineenModel::where('family_id', $familyId)
                ->where('hof_type', 'FM')
                ->where('status', 'active')
                ->orderByRaw('(SELECT MAX(age) FROM t_mumineen AS m2 WHERE m2.family_its = t_mumineen.family_its AND m2.family_id = ' . $familyId . ' AND m2.hof_type = \'FM\' AND m2.status = \'active\') DESC')
                ->orderBy('family_its', 'asc')
                ->orderBy('age', 'desc')
                ->get();

            if ($members->isEmpty()) {
                return $this->error('Family not found or no active family members', 404);
            }

            // Format members data
            $familyMembers = [];
            foreach ($members as $member) {
                $familyMembers[] = [
                    'id' => (string) $member->id,
                    'family_id' => (string) $member->family_id,
                    'hof_type' => $member->hof_type,
                    'its' => $member->its,
                    'name' => $member->name,
                    'sector' => $member->sector ?? '',
                    'sub_sector' => $member->sub_sector ?? '',
                    'mobile' => $member->mobile ?? '',
                    'email' => $member->email ?? '',
                    'gender' => $member->gender,
                    'age' => $member->age,
                    'pic' => $member->pic,
                    'status' => $member->status,
                ];
            }

            return $this->success('Family members fetched successfully', [
                'family_id' => (string) $familyId,
                'members' => $familyMembers,
                'total_members' => count($familyMembers),
            ], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Family members fetch failed');
        }
    }

    // export
    public function export(Request $request)
    {
        try {
            [$currentYear, $prevYear, $yearsList] = $this->resolveYears();

            $search = trim((string) $request->input('search', ''));
            $sector = trim((string) $request->input('sector', ''));
            $filter = trim((string) $request->input('filter', ''));

            $q = MumineenModel::where('hof_type','HOF')
                ->where('status', 'active')
                ->whereNotIn('its', ['20320125', '20303586', '30350003'])
                ->orderByRaw("CASE 
                    WHEN sector = 'BURHANI' THEN 1 
                    WHEN sector = 'EZZY' THEN 2 
                    WHEN sector = 'MOHAMMEDI' THEN 3 
                    WHEN sector = 'SHUJAI' THEN 4 
                    WHEN sector = 'ZAINY' THEN 5 
                    ELSE 6 
                END")
                ->orderBy('sub_sector', 'asc')
                ->orderBy('name','asc');


            if ($search !== '') {
                $this->applySmartSearch($q, $search, ['name', 'its', 'sector'], function ($query, $keyword) {
                    $query->orWhereIn('family_id', function($sub) use ($keyword) {
                        $sub->from('t_mumineen')
                            ->select('family_id')
                            ->where('its', 'like', "%{$keyword}%");
                    });
                });
            }

            if ($sector !== '') $q->where('sector', $sector);

            if ($filter !== '') $q = $this->applyFilter($q, $filter, $currentYear, $prevYear);

            // ✅ NO pagination
            $rows = $this->buildPayload(
                $q->get(),
                $currentYear,
                $prevYear,
                $yearsList
            );

            $excelRows = [];
            $sn = 1;

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

    private function familyDueForYear(int $familyId, string $year): array
    {
        $sabeel = (int) MumineenSabeelModel::where('family_id', $familyId)
            ->where('year', $year)
            ->value('sabeel');

        $paid = (float) ReceiptModel::where('family_id', $familyId)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');

        // Include advance_paid (pending only) - since advance_paid doesn't have year, we can't allocate per year
        // For year-wise calculations, we only consider receipts
        // Advance_paid is considered in total due calculations

        $due = max(0, $sabeel - $paid);

        return [$sabeel, $due];
    }

    private function establishmentTotalsDueForYear(array $estCodes, string $year): array
    {
        if (empty($estCodes)) return [0, 0];

        $sabeelSum = (int) EstablishmentSabeelModel::whereIn('establishment_id', $estCodes)
            ->where('year', $year)
            ->sum('sabeel');

        $paidSum = (float) ReceiptModel::whereIn('establishment_id', $estCodes)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');

        // Include advance_paid (pending only) - since advance_paid doesn't have year, we can't allocate per year
        // For year-wise calculations, we only consider receipts
        // Advance_paid is considered in total due calculations

        $dueSum = max(0, $sabeelSum - $paidSum);

        return [$sabeelSum, $dueSum];
    }

    /**
     * Calculate total due for all years before current year (for prev_due)
     */
    private function familyTotalDueForAllPreviousYears(int $familyId, string $currentYear): float
    {
        // Get all sabeel entries for years < current year
        $sabeelEntries = MumineenSabeelModel::where('family_id', $familyId)
            ->where('year', '<', $currentYear)
            ->get();

        $totalPrevDue = 0;

        foreach ($sabeelEntries as $entry) {
            $year = $entry->year;
            $sabeel = (float) $entry->sabeel;
            $paid = (float) ReceiptModel::where('family_id', $familyId)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');
            $due = max(0, $sabeel - $paid);
            $totalPrevDue += $due;
        }

        return $totalPrevDue;
    }

    /**
     * Calculate total establishment due for all years before current year (for prev_due)
     */
    private function establishmentTotalDueForAllPreviousYears(array $estCodes, string $currentYear): float
    {
        if (empty($estCodes)) return 0;

        // Get all sabeel entries for years < current year
        $sabeelEntries = EstablishmentSabeelModel::whereIn('establishment_id', $estCodes)
            ->where('year', '<', $currentYear)
            ->get()
            ->groupBy('year');

        $totalPrevDue = 0;

        foreach ($sabeelEntries as $year => $entries) {
            $sabeelSum = (float) $entries->sum('sabeel');
            $paidSum = (float) ReceiptModel::whereIn('establishment_id', $estCodes)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');
            $due = max(0, $sabeelSum - $paidSum);
            $totalPrevDue += $due;
        }

        return $totalPrevDue;
    }

    /**
     * List all active users (HOF & FM) with its and name only
     * GET /mumineen/list-all
     */
    public function listAll(Request $request)
    {
        try {
            $users = MumineenModel::where('status', 'active')
                ->select('its', 'name')
                ->orderBy('name', 'asc')
                ->get();

            $data = $users->map(function ($user) {
                return [
                    'its' => (string) $user->its,
                    'name' => (string) $user->name,
                ];
            });

            return $this->success('Users fetched successfully', $data->values()->all(), 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Users list fetch failed');
        }
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

    public function syncAllPhotos()
    {
        $mumineens = MumineenModel::all(); // Fetch all Mumineen records

        foreach ($mumineens as $mumineen) {
            $mumineen->syncPhotos(); // Sync photos for each Mumineen
            $mumineen->save(); // Save the updated record
        }

        return response()->json([
            'message' => 'Photos synced successfully.',
        ], 200);
    }

    // public function syncPhotosFromRemote(Request $request)
    // {
    //     try {
    //         $placeholder = 'https://api.kolkatajamaat.com/storage/uploads/its_images/placeholder.jpg';

    //         // optional controls
    //         $limit   = max(1, (int) $request->input('limit', 200));   // process N records per call
    //         $offset  = max(0, (int) $request->input('offset', 0));
    //         $dryRun  = (bool) $request->input('dry_run', false);      // if true: no download/update
    //         $timeout = max(5, (int) $request->input('timeout', 15));  // seconds

    //         // ensure folder exists
    //         Storage::disk('public')->makeDirectory('uploads/its_images');

    //         // fetch mumineen who still have placeholder
    //         $q = MumineenModel::query()
    //             ->select('id', 'its', 'pic')
    //             ->where('pic', $placeholder)
    //             ->whereNotNull('its')
    //             ->where('its', '!=', '');

    //         $totalCandidates = (clone $q)->count();

    //         $rows = (clone $q)
    //             ->orderBy('id', 'asc')
    //             ->skip($offset)
    //             ->take($limit)
    //             ->get();

    //         $processed = 0;
    //         $downloaded = 0;
    //         $notFound = 0;
    //         $skipped = 0;
    //         $failed = 0;
    //         $failedRows = [];

    //         foreach ($rows as $m) {
    //             $processed++;

    //             $its = trim((string) $m->its);
    //             if ($its === '') {
    //                 $skipped++;
    //                 $failedRows[] = [
    //                     'id'  => $m->id,
    //                     'its' => $its,
    //                     'reason' => 'ITS empty',
    //                 ];
    //                 continue;
    //             }

    //             // remote URL pattern you gave
    //             $remoteUrl = "https://talabulilm.com/mumin_images/{$its}.png";

    //             // local store path
    //             $relativePath = "uploads/its_images/{$its}.png"; // storage/app/public/...
    //             $publicUrl    = url("storage/{$relativePath}");

    //             try {
    //                 // If already downloaded earlier (file exists), just update DB (optional)
    //                 if (Storage::disk('public')->exists($relativePath)) {
    //                     if (!$dryRun) {
    //                         MumineenModel::where('id', $m->id)->update(['pic' => $publicUrl]);
    //                     }
    //                     $downloaded++;
    //                     continue;
    //                 }

    //                 // HEAD check (fast)
    //                 $head = Http::timeout($timeout)->withoutVerifying()->head($remoteUrl);

    //                 if ($head->successful()) {
    //                     if ($dryRun) {
    //                         $downloaded++;
    //                         continue;
    //                     }

    //                     // GET the file
    //                     $resp = Http::timeout($timeout)->withoutVerifying()->get($remoteUrl);

    //                     if (!$resp->successful() || empty($resp->body())) {
    //                         $failed++;
    //                         $failedRows[] = [
    //                             'id'  => $m->id,
    //                             'its' => $its,
    //                             'reason' => 'Remote GET failed or empty body',
    //                             'remote_url' => $remoteUrl,
    //                             'status' => $resp->status(),
    //                         ];
    //                         continue;
    //                     }

    //                     // store file
    //                     Storage::disk('public')->put($relativePath, $resp->body());

    //                     // update DB pic url
    //                     MumineenModel::where('id', $m->id)->update(['pic' => $publicUrl]);

    //                     $downloaded++;
    //                 } else {
    //                     $notFound++;
    //                 }

    //             } catch (\Throwable $ex) {
    //                 $failed++;
    //                 $failedRows[] = [
    //                     'id'  => $m->id,
    //                     'its' => $its,
    //                     'reason' => $ex->getMessage(),
    //                     'remote_url' => $remoteUrl,
    //                 ];
    //             }
    //         }

    //         return $this->success('Photo sync completed.', [
    //             'total_candidates' => $totalCandidates,
    //             'processed'        => $processed,
    //             'downloaded'       => $downloaded,
    //             'not_found'        => $notFound,
    //             'skipped'          => $skipped,
    //             'failed'           => $failed,
    //             'failed_rows'      => $failedRows,
    //             'next_offset'      => $offset + $limit,
    //             'limit'            => $limit,
    //             'dry_run'          => $dryRun,
    //         ], 200);

    //     } catch (\Throwable $e) {
    //         return $this->serverError($e, 'Mumineen photo sync failed');
    //     }
    // }
    public function syncPhotosFromRemote(Request $request)
    {
        try {
            $placeholder = 'https://api.kolkatajamaat.com/storage/uploads/its_images/placeholder.jpg';

            $limit      = max(1, (int) $request->input('limit', 200));
            $offset     = max(0, (int) $request->input('offset', 0));
            $timeBudget = max(5, (int) $request->input('time_budget', 20)); // seconds
            $timeout    = max(3, (int) $request->input('timeout', 8));      // per HTTP call

            $startedAt = microtime(true);

            // prevent PHP max execution (still nginx can timeout)
            @set_time_limit($timeBudget + 10);

            Storage::disk('public')->makeDirectory('uploads/its_images');

            $q = MumineenModel::query()
                ->select('id','its','pic')
                ->where('pic', $placeholder)
                ->whereNotNull('its')
                ->where('its','!=','')
                ->orderBy('id','asc');

            $totalCandidates = (clone $q)->count();

            $rows = (clone $q)->skip($offset)->take($limit)->get();

            $processed = $downloaded = $notFound = $failed = 0;
            $failedRows = [];

            foreach ($rows as $m) {
                // ✅ stop before nginx kills you
                if ((microtime(true) - $startedAt) > $timeBudget) {
                    break;
                }

                $processed++;
                $its = trim((string)$m->its);

                $remoteUrl = "https://talabulilm.com/mumin_images/{$its}.png";
                $relativePath = "uploads/its_images/{$its}.png";
                $publicUrl = url("storage/{$relativePath}");

                try {
                    // already exists locally
                    if (Storage::disk('public')->exists($relativePath)) {
                        MumineenModel::where('id',$m->id)->update(['pic'=>$publicUrl]);
                        $downloaded++;
                        continue;
                    }

                    // ✅ single GET (avoid HEAD; many servers block HEAD / slower overall)
                    $resp = Http::timeout($timeout)
                        ->retry(2, 200)         // 2 retries, 200ms
                        ->withoutVerifying()
                        ->get($remoteUrl);

                    if ($resp->status() === 404) {
                        $notFound++;
                        continue;
                    }

                    if (!$resp->successful() || empty($resp->body())) {
                        $failed++;
                        $failedRows[] = [
                            'id' => $m->id,
                            'its' => $its,
                            'status' => $resp->status(),
                            'reason' => 'Remote response not successful / empty body'
                        ];
                        continue;
                    }

                    Storage::disk('public')->put($relativePath, $resp->body());
                    MumineenModel::where('id',$m->id)->update(['pic'=>$publicUrl]);
                    $downloaded++;

                } catch (\Throwable $ex) {
                    $failed++;
                    $failedRows[] = [
                        'id' => $m->id,
                        'its' => $its,
                        'reason' => $ex->getMessage(),
                    ];
                }
            }

            $nextOffset = $offset + $processed;
            $done = ($nextOffset >= $totalCandidates) || ($processed === 0);

            return $this->success('Photo sync partial run completed.', [
                'total_candidates' => $totalCandidates,
                'offset' => $offset,
                'processed' => $processed,
                'downloaded' => $downloaded,
                'not_found' => $notFound,
                'failed' => $failed,
                'failed_rows' => $failedRows,
                'next_offset' => $done ? null : $nextOffset,
                'done' => $done,
                'time_budget' => $timeBudget,
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen photo sync failed');
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
        // Get current year as string (format: "2024-25")
        $currentYearRecord = YearModel::where('is_current', 1)->first();
        if (!$currentYearRecord) {
            $currentYearRecord = YearModel::orderBy('year', 'desc')->first();
        }
        $currentYear = $currentYearRecord ? $currentYearRecord->year : (string) date('Y');

        $yearsList = YearModel::orderBy('year','desc')->pluck('year')->toArray();
        if (empty($yearsList)) {
            $currentYearInt = (int) date('Y');
            $yearsList = [(string)$currentYearInt, (string)($currentYearInt - 1), (string)($currentYearInt - 2)];
        }

        // Get previous year (string format)
        $prevYearRecord = YearModel::where('year', '<', $currentYear)->orderBy('year', 'desc')->first();
        $prevYear = $prevYearRecord ? $prevYearRecord->year : '';

        return [$currentYear, $prevYear, $yearsList];
    }

    private function applyFilter($q, string $filter, int $currentYear, int $prevYear)
    {
        if ($filter === 'not_tagged') {
            return $q->whereNotIn('family_id', function($sub){
                $sub->from('t_mumineen_establishment')->select('family_id');
            });
        }

        if ($filter === 'external') {
            return $q->where('external', true);
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

    private function buildPayload($rows, string $currentYear, string $prevYear, array $yearsList): array
    {
        $familyIds = collect($rows)->pluck('family_id')->filter()->unique()->values()->all();
        if (empty($familyIds)) return [];

        // Check if verification columns exist (once for all rows)
        $hasIsVerified = Schema::hasColumn('t_mumineen', 'is_verified');
        $hasIsTakhmeenUpdated = Schema::hasColumn('t_mumineen', 'is_takhmeen_updated');

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

        // Establishment sabeel (for names/display)
        $es = empty($estIds) ? collect() : EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
            ->get()
            ->groupBy('establishment_id');

        // Centralized due calculation (includes advance_paid)
        $dueService = app(DueCalculationService::class);
        $familyDueBulk = $dueService->getFamilyDueBulk($familyIds, $currentYear);
        $estDueBulk = empty($estIds) ? [] : $dueService->getEstablishmentDueBulk($estIds, $currentYear);

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

                if ($yr === $currentYear) { $curSabeel = $sabeelAmt; $curDue = $due; }
            }

            $famDue = $familyDueBulk[(int)$m->family_id] ?? null;
            if ($famDue) {
                $curDueEffective = $famDue['due_effective'];
                $prevDueEffective = $famDue['prev_due_effective'];
            } else {
                $prevDue = $this->familyTotalDueForAllPreviousYears((int)$m->family_id, (string)$currentYear);
                $curDueEffective = $curDue;
                $prevDueEffective = $prevDue;
            }
            if ($famDue) {
                foreach ($sabeelDetails as &$sd) {
                    if (($sd['year'] ?? '') === $this->yearLabel((int)$currentYear)) {
                        $sd['due'] = (string) $famDue['due_effective'];
                        break;
                    }
                }
                unset($sd);
            }

            $familyLinks = $links->get($m->family_id) ?? collect();
            $estCodesForFamily = $familyLinks->unique('establishment_id')->pluck('establishment_id')->filter()->unique()->values()->all();
            $estTotals = $dueService->getEstablishmentTotalsForFamily($estCodesForFamily, $currentYear);

            $estDetails = [];
            foreach ($familyLinks->unique('establishment_id') as $lnk) {
                $estId = (string) $lnk->establishment_id;
                $estName = (string) (optional($lnk->establishment)->name ?? '');
                $eDue = $estDueBulk[$estId] ?? null;
                $estSabeelCur = $eDue ? $eDue['sabeel'] : (int) optional($es->get($estId))?->firstWhere('year', $currentYear)?->sabeel ?? 0;
                $estDueCurEffective = $eDue ? $eDue['due_effective'] : 0;

                $estDetails[] = [
                    'establishment_id' => $estId,
                    'name'             => $estName,
                    'sabeel'           => (string) $estSabeelCur,
                    'due'              => (string) $estDueCurEffective,
                ];
            }

            $out[] = [
                'id'        => (string) $m->id,
                'family_id' => (string) $m->family_id,
                // 'url'       => "https://talabulilm.com/mumin_images/{$m->its}.png",
                'url'       => (string) $m->pic,

                'name'      => (string) $m->name,
                'its'       => (string) $m->its,
                'sector'    => (string) ($m->sector ?? ''),
                'mobile'    => (string) ($m->mobile ?? ''),
                'email'     => (string) ($m->email ?? ''),
                'is_verified' => $hasIsVerified ? (bool) ($m->is_verified ?? false) : false,
                'is_takhmeen_updated' => $hasIsTakhmeenUpdated ? (bool) ($m->is_takhmeen_updated ?? false) : false,

                'sabeel' => [
                    'sabeel'   => (string) $curSabeel,
                    'due'      => (string) $curDueEffective,
                    'prev_due' => (string) $prevDueEffective,
                ],

                'establishment' => [
                    'sabeel'   => (string) $estTotals['sabeel'],
                    'due'      => (string) $estTotals['due_effective'],
                    'prev_due' => (string) $estTotals['prev_due_effective'],
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

    /**
     * UPDATE VERIFICATION FLAGS
     * POST /family/update-verification/{family_id}
     * Body: { "is_verified": true, "is_takhmeen_updated": false }
     * Both fields are optional - can update one or both
     */
    public function updateVerification(Request $request, $family_id)
    {
        try {
            $mumineen = MumineenModel::where('family_id', (int) $family_id)
                ->where('hof_type', 'HOF')
                ->first();

            if (!$mumineen) {
                return $this->error('Mumineen not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'is_verified' => 'nullable|boolean',
                'is_takhmeen_updated' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // Build update array - only include fields that are provided
            $updateData = [];
            if ($request->has('is_verified')) {
                $updateData['is_verified'] = (bool) $request->is_verified;
            }
            if ($request->has('is_takhmeen_updated')) {
                $updateData['is_takhmeen_updated'] = (bool) $request->is_takhmeen_updated;
            }

            if (empty($updateData)) {
                return $this->error('At least one field (is_verified or is_takhmeen_updated) must be provided.', 400);
            }

            $mumineen->update($updateData);

            return $this->success('Verification flags updated successfully', [
                'family_id' => $mumineen->family_id,
                'is_verified' => $mumineen->is_verified,
                'is_takhmeen_updated' => $mumineen->is_takhmeen_updated,
            ], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Verification update failed');
        }
    }

    /**
     * Close family sabeel (mark whole family closed)
     * POST /family/close-sabeel/{family_id}
     */
    public function closeSabeel($family_id)
    {
        try {
            $familyId = (int) $family_id;

            $hof = MumineenModel::where('family_id', $familyId)
                ->where('hof_type', 'HOF')
                ->first();

            if (!$hof) {
                return $this->error('Family not found.', 404);
            }

            if (strtolower((string) $hof->status) === 'closed') {
                return $this->success('Family sabeel is already closed.', [
                    'family_id' => (string) $familyId,
                    'status' => 'closed',
                ], 200);
            }

            // Close all rows for this family (HOF + members) to keep data consistent.
            MumineenModel::where('family_id', $familyId)->update(['status' => 'closed']);

            return $this->success('Family sabeel closed successfully.', [
                'family_id' => (string) $familyId,
                'status' => 'closed',
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Close family sabeel failed');
        }
    }

    /**
     * Generate Sector Due PDF
     * GET /family/sector-due-pdf/{sector}
     * Example: /family/sector-due-pdf/BURHANI
     */
    public function generateSectorDuePdf(Request $request, $sector)
    {
        try {
            $validator = Validator::make(['sector' => $sector], [
                'sector' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'code' => 422,
                    'status' => 'error',
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()->all(),
                    'debug' => [
                        'sector_received' => $sector,
                    ],
                ], 422, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            $sector = trim($sector);

            // Get current year
            [$currentYear, $prevYear] = $this->resolveYearsSimple();
            $currentYearStr = "2025-26";

            // Get all families in the sector (using LIKE for partial matching)
            $selectFields = ['id', 'family_id', 'its', 'name', 'mobile'];
            // Add is_takhmeen_updated if column exists
            if (Schema::hasColumn('t_mumineen', 'is_takhmeen_updated')) {
                $selectFields[] = 'is_takhmeen_updated';
            }

            $families = MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->where('sector', 'like', '%' . $sector . '%')
                ->whereNotIn('its', ['20320125', '20303586', '30350003'])
                ->select($selectFields)
                ->orderByRaw("CASE 
                    WHEN sector = 'BURHANI' THEN 1 
                    WHEN sector = 'EZZY' THEN 2 
                    WHEN sector = 'MOHAMMEDI' THEN 3 
                    WHEN sector = 'SHUJAI' THEN 4 
                    WHEN sector = 'ZAINY' THEN 5 
                    ELSE 6 
                END")
                ->orderBy('sub_sector', 'asc')
                ->orderBy('name', 'asc')
                ->get();

            if ($families->isEmpty()) {
                return response()->json([
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'No families found for sector: ' . $sector,
                    'debug' => [
                        'sector' => $sector,
                        'sector_like_pattern' => '%' . $sector . '%',
                        'current_year' => $currentYearStr,
                    ],
                ], 404, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            $familyIds = $families->pluck('family_id')->unique()->values()->all();

            $links = MumineenEstablishmentModel::with('establishment')
                ->whereIn('family_id', $familyIds)
                ->get()
                ->groupBy('family_id');

            // Process families and filter by total due (due from DueCalculationService includes advance_paid)
            $pdfData = [];
            $serialNumber = 1;
            $totalFamilies = 0;
            $totalEstablishments = 0;
            $skippedFamilies = [];

            $dueService = app(DueCalculationService::class);

            foreach ($families as $family) {
                $familyId = $family->family_id;
                $famDue = $dueService->getFamilyDue($familyId, $currentYearStr);
                $familySabeelCur = $famDue['sabeel'];
                $familyDueCur = $famDue['due_effective'];
                $familyPrevDue = $famDue['prev_due_effective'];

                $familyLinks = $links->get($familyId) ?? collect();
                $establishments = [];
                foreach ($familyLinks->unique('establishment_id') as $lnk) {
                    $estId = (string) $lnk->establishment_id;
                    $estName = (string) (optional($lnk->establishment)->name ?? '');
                    $eDue = $dueService->getEstablishmentDue($estId, $currentYearStr);
                    $estTotalDue = $eDue['due_effective'] + $eDue['prev_due_effective'];
                    if ($estTotalDue > 0) {
                        $establishments[] = [
                            'name' => $estName,
                            'hub' => $eDue['sabeel'],
                            'due' => $eDue['due_effective'],
                            'prev_due' => $eDue['prev_due_effective'],
                        ];
                        $totalEstablishments++;
                    }
                }

                if ($familyDueCur == 0 && empty($establishments)) {
                    continue;
                }

                $remark = '';
                if (Schema::hasColumn('t_mumineen', 'is_takhmeen_updated')) {
                    $isTakhmeenUpdated = $family->is_takhmeen_updated ?? false;
                    if (!$isTakhmeenUpdated) {
                        $remark = 'Takhmeen Not Done';
                    }
                }

                $pdfData[] = [
                    'sn' => $serialNumber++,
                    'its' => (string) $family->its,
                    'name' => (string) $family->name,
                    'mobile' => (string) ($family->mobile ?? ''),
                    'hub' => $familySabeelCur,
                    'due' => $familyDueCur,
                    'prev_due' => $familyPrevDue,
                    'remark' => $remark,
                    'establishments' => $establishments,
                ];

                $totalFamilies++;
            }

            if (empty($pdfData)) {
                return response()->json([
                    'code' => 404,
                    'status' => 'error',
                    'message' => 'No families with due found for sector: ' . $sector,
                    'debug' => [
                        'sector' => $sector,
                        'current_year' => $currentYearStr,
                        'total_families_found' => $families->count(),
                        'reason' => 'All families have due == 0 for current year',
                    ],
                ], 404, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            // Generate PDF
            $html = view('sector_due_pdf', [
                'sector' => $sector,
                'currentYear' => $currentYear,
                'data' => $pdfData,
                'generatedDate' => date('d-m-Y'),
            ])->render();

            // Initialize mPDF
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P',
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 15,
                'margin_bottom' => 15,
            ]);

            $mpdf->WriteHTML($html);

            // Generate PDF output
            $filename = 'sector_due_' . str_replace(' ', '_', $sector) . '_' . date('Y-m-d') . '.pdf';
            $pdfOutput = $mpdf->Output('', 'S');

            // Return PDF directly to browser
            return response()->make($pdfOutput, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
                'Cache-Control' => 'public, max-age=0',
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'code' => 500,
                'status' => 'error',
                'message' => 'Sector due PDF generation failed',
                'debug' => [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'sector' => $sector ?? 'unknown',
                ],
            ], 500, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }

}
