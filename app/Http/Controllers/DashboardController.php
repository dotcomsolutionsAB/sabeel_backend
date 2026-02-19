<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\GenericExcelExport;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Support\Facades\Schema;
use App\Helpers\ExcelExportHelper;
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;
use App\Models\AdvancePaidModel;
use App\Services\DueCalculationService;
use Illuminate\Database\Eloquent\Builder;

class DashboardController extends Controller
{
    //
    use ApiResponse;

    /**
     * Get current year from database
     */
    private function getCurrentYear(): string
    {
        $currentYear = YearModel::where('is_current', 1)->value('year');
        if (!$currentYear) {
            $currentYear = YearModel::orderBy('year', 'desc')->value('year');
        }
        return $currentYear ?: (string) date('Y');
    }

    public function retrieve()
    {
        try {
            $currentYearStr = $this->getCurrentYear();

            /* ================= MUMINEEN ================= */

            $totalHouses = MumineenModel::where('status', 'active')
                ->selectRaw('COUNT(DISTINCT family_id) as count')
                ->value('count') ?? 0;

            $externalHouses = MumineenModel::where('status', 'active')
                ->where('external', true)
                ->selectRaw('COUNT(DISTINCT family_id) as count')
                ->value('count') ?? 0;

            // Get all active HOF family_ids
            $activeHofFamilyIds = MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->selectRaw('DISTINCT family_id')
                ->pluck('family_id')
                ->all();

            // Calculate total sabeel for active HOFs only (current year only)
            $totalMumineenSabeel = DB::table('t_mumineen_sabeel')
                ->whereIn('family_id', $activeHofFamilyIds)
                ->where('year', $currentYearStr)
                ->sum('sabeel');

            // Calculate paid amount for active HOFs only (current year only)
            $paidMumineen = DB::table('t_mumineen_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.family_id', '=', 'r.family_id')
                    ->on('s.year', '=', 'r.year')
                    ->where('r.status', 'active');
                })
                ->whereIn('s.family_id', $activeHofFamilyIds)
                ->where('s.year', $currentYearStr)
                ->select(DB::raw('COALESCE(SUM(r.amount),0) as paid'))
                ->value('paid') ?? 0;

            // Include advance_paid (pending only) in total paid
            $advancePaidMumineen = AdvancePaidModel::whereIn('family_id', $activeHofFamilyIds)
                ->where('type', 'family')
                ->where('status', 'pending')
                ->sum('amount');

            $dueMumineenSabeel = max(0, $totalMumineenSabeel - $paidMumineen - (float) $advancePaidMumineen);

            // Count unique family_id that has due in any one year (year-wise matching)
            $dueHouses = DB::table('t_mumineen_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.family_id', '=', 'r.family_id')
                    ->on('s.year', '=', 'r.year')
                    ->where('r.status', 'active');
                })
                ->whereIn('s.family_id', $activeHofFamilyIds)
                ->select('s.family_id')
                ->groupBy('s.family_id', 's.year')
                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)')
                ->pluck('family_id')
                ->unique()
                ->count();

            $havingPrevDue = DB::table('t_mumineen_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.family_id', '=', 'r.family_id')
                    ->where('r.status', 'active');
                })
                ->where('s.year', '<', $currentYearStr)
                ->select(
                    's.family_id',
                    DB::raw('SUM(r.amount) as paid'),
                    DB::raw('MAX(s.sabeel) as sabeel')
                )
                ->groupBy('s.family_id')
                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)')
                ->count();

            $newTakhmeenPending = MumineenModel::whereNotIn('family_id', function ($q) use ($currentYearStr) {
                    $q->select('family_id')
                      ->from('t_mumineen_sabeel')
                      ->where('year', $currentYearStr);
                })->count();

            // Count unique family_id not tagged to any establishment (only active HOFs)
            $establishmentMissing = MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->whereNotIn('family_id', function ($q) {
                    $q->select('family_id')
                      ->from('t_mumineen_establishment');
                })
                ->select(DB::raw('COUNT(DISTINCT family_id) as count'))
                ->value('count') ?? 0;

            /* ================= ESTABLISHMENT ================= */

            $totalEstablishment = EstablishmentModel::count();

            // Get all establishment IDs
            $allEstablishmentIds = EstablishmentModel::pluck('establishment_id')->all();

            // Calculate total sabeel for all establishments (current year only)
            $totalEstSabeel = EstablishmentSabeelModel::where('year', $currentYearStr)->sum('sabeel');

            // Calculate paid amount for all establishments (current year only)
            $paidEst = DB::table('t_establishment_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.establishment_id', '=', 'r.establishment_id')
                    ->on('s.year', '=', 'r.year')
                    ->where('r.status', 'active');
                })
                ->whereIn('s.establishment_id', $allEstablishmentIds)
                ->where('s.year', $currentYearStr)
                ->select(DB::raw('COALESCE(SUM(r.amount),0) as paid'))
                ->value('paid') ?? 0;

            // Include advance_paid (pending only) in total paid
            $advancePaidEst = AdvancePaidModel::whereIn('establishment_id', $allEstablishmentIds)
                ->where('type', 'establishment')
                ->where('status', 'pending')
                ->sum('amount');

            $dueEstSabeel = max(0, $totalEstSabeel - $paidEst - (float) $advancePaidEst);

            // Count establishments that have due in any one year (year-wise matching)
            $dueEstablishment = DB::table('t_establishment_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.establishment_id', '=', 'r.establishment_id')
                    ->on('s.year', '=', 'r.year')
                    ->where('r.status', 'active');
                })
                ->whereIn('s.establishment_id', $allEstablishmentIds)
                ->select('s.establishment_id')
                ->groupBy('s.establishment_id', 's.year')
                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)')
                ->pluck('establishment_id')
                ->unique()
                ->count();

            $partnerNotTagged = EstablishmentModel::whereNotIn('establishment_id', function ($q) {
                    $q->select('establishment_id')
                    ->from('t_mumineen_establishment');
                })->count();

            $manufacturer = EstablishmentModel::where('type', 'manufacturer')->count();

            return $this->success('Dashboard data fetched', [
                'mumineen' => [
                    'total_houses'         => (string) $totalHouses,
                    'external_houses'      => (string) $externalHouses,
                    'total_sabeel'         => (string) $totalMumineenSabeel,
                    'due_houses'           => (string) $dueHouses,
                    'due_sabeel'           => (string) $dueMumineenSabeel,
                    'having_prev_due'      => (string) $havingPrevDue,
                    'new_takhmeen_pending' => (string) $newTakhmeenPending,
                    'establishment_missing'=> (string) $establishmentMissing,
                    'external_families'    => (string) $externalHouses,
                ],
                'establishment' => [
                    'total_establishment'  => (string) $totalEstablishment,
                    'total_sabeel'         => (string) $totalEstSabeel,
                    'due_establishment'    => (string) $dueEstablishment,
                    'due_sabeel'           => (string) $dueEstSabeel,
                    'having_prev_due'      => '',
                    'new_takhmeen_pending' => '',
                    'partner_not_tagged'   => (string) $partnerNotTagged,
                    'manufacturer'         => (string) $manufacturer,
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Dashboard retrieve failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return error details in response for debugging
            return $this->error('Dashboard retrieve failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500, [
                'error_details' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => config('app.debug') ? $e->getTraceAsString() : 'Trace available in logs',
                ],
            ]);
        }
    }

    public function retrieveSabeelDue(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|in:sabeel,establishment',
            ]);

            $dueService = app(DueCalculationService::class);
            $currentYearStr = $dueService->getCurrentYear();
            $yearDueMap = [];

            if ($request->type === 'sabeel') {
                $hofs = MumineenModel::query()
                    ->where('hof_type', 'HOF')
                    ->where('status', 'active')
                    ->select('family_id')
                    ->get();

                $familyIds = $hofs->pluck('family_id')->unique()->all();

                if (!empty($familyIds)) {
                    $sabeelData = DB::table('t_mumineen_sabeel')
                        ->whereIn('family_id', $familyIds)
                        ->select('family_id', 'year', 'sabeel')
                        ->get()
                        ->groupBy('family_id');

                    $paidData = DB::table('t_receipts')
                        ->whereIn('family_id', $familyIds)
                        ->where('status', 'active')
                        ->select('family_id', 'year', DB::raw('SUM(amount) as paid'))
                        ->groupBy('family_id', 'year')
                        ->get()
                        ->groupBy('family_id');

                    $familyDueBulk = $dueService->getFamilyDueBulk($familyIds, $currentYearStr);

                    foreach ($familyIds as $familyId) {
                        $familySabeels = $sabeelData->get($familyId, collect());
                        $familyPaids = $paidData->get($familyId, collect());
                        $years = $familySabeels->pluck('year')->merge($familyPaids->pluck('year'))->unique()->sort();

                        foreach ($years as $yr) {
                            $sabeelEntry = $familySabeels->firstWhere('year', $yr);
                            $paidEntry = $familyPaids->firstWhere('year', $yr);
                            $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                            $paid   = (float) ($paidEntry->paid ?? 0);
                            $due = max(0, $sabeel - $paid);
                            if ($yr === $currentYearStr && isset($familyDueBulk[$familyId])) {
                                $due = $familyDueBulk[$familyId]['due_effective'];
                            }
                            if (!isset($yearDueMap[$yr])) {
                                $yearDueMap[$yr] = 0;
                            }
                            $yearDueMap[$yr] += $due;
                        }
                    }
                }
            } else {
                $establishments = EstablishmentModel::query()
                    ->select('establishment_id')
                    ->get();

                $estIds = $establishments->pluck('establishment_id')->all();

                if (!empty($estIds)) {
                    $sabeelData = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
                        ->select('establishment_id', 'year', 'sabeel')
                        ->get()
                        ->groupBy('establishment_id');

                    $paidData = DB::table('t_receipts')
                        ->whereIn('establishment_id', $estIds)
                        ->where('status', 'active')
                        ->select('establishment_id', 'year', DB::raw('SUM(amount) as paid'))
                        ->groupBy('establishment_id', 'year')
                        ->get()
                        ->groupBy('establishment_id');

                    $estDueBulk = $dueService->getEstablishmentDueBulk($estIds, $currentYearStr);

                    foreach ($estIds as $estId) {
                        $estSabeels = $sabeelData->get($estId, collect());
                        $estPaids = $paidData->get($estId, collect());
                        $years = $estSabeels->pluck('year')->merge($estPaids->pluck('year'))->unique()->sort();

                        foreach ($years as $yr) {
                            $sabeelEntry = $estSabeels->firstWhere('year', $yr);
                            $paidEntry = $estPaids->firstWhere('year', $yr);
                            $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                            $paid   = (float) ($paidEntry->paid ?? 0);
                            $due = max(0, $sabeel - $paid);
                            if ($yr === $currentYearStr && isset($estDueBulk[$estId])) {
                                $due = $estDueBulk[$estId]['due_effective'];
                            }
                            if (!isset($yearDueMap[$yr])) {
                                $yearDueMap[$yr] = 0;
                            }
                            $yearDueMap[$yr] += $due;
                        }
                    }
                }
            }

            // Convert to array format
            $data = collect($yearDueMap)->map(function ($due, $year) {
                return [
                    'year' => (string) $year,
                    'due'  => (string) $due,
                ];
            })->values()->sortByDesc('year')->values()->all();

            return $this->success('Sabeel due fetched', $data, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Sabeel due fetch failed');
        }
    }

    public function exportEstablishment(Request $request)
    {
        try {
            // Increase timeout and memory for large exports
            set_time_limit(600); // 10 minutes
            ini_set('memory_limit', '512M');
            
            $filter = trim((string) $request->input('filter', ''));
            $year = $request->input('year'); // Optional year parameter

            if (!in_array($filter, ['family','due_family','establishment','due_establishment'], true)) {
                return $this->error('Invalid filter. Allowed: family, due_family, establishment, due_establishment', 422);
            }

            // Determine which year(s) to use
            $useSpecificYear = !empty($year);
            $targetYear = $useSpecificYear ? (string) $year : null;
            $currentYearStr = $useSpecificYear ? $targetYear : $this->getCurrentYear();

            /* ============================================================
            FAMILY EXPORT
            ============================================================ */
            if ($filter === 'family' || $filter === 'due_family') {

                // Base: all active HOF
                $q = MumineenModel::query()
                    ->where('hof_type', 'HOF')
                    ->where('status', 'active')
                    ->select('id','family_id','its','name','sector','mobile','email')
                    ->orderBy('name', 'asc');

                // If due_family -> only those having due (paid < sabeel)
                if ($filter === 'due_family') {
                    if ($useSpecificYear) {
                        $q->whereIn('family_id', function ($sub) use ($targetYear) {
                        $sub->from('t_mumineen_sabeel as s')
                                ->leftJoin('t_receipts as r', function ($j) use ($targetYear) {
                                $j->on('s.family_id', '=', 'r.family_id')
                                    ->where('r.status', 'active')
                                    ->where('r.year', $targetYear);
                            })
                                ->where('s.year', $targetYear)
                            ->select('s.family_id')
                            ->groupBy('s.family_id')
                            ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)');
                    });
                    } else {
                        // For all years, check if any year has due
                        $q->whereIn('family_id', function ($sub) {
                            $sub->from('t_mumineen_sabeel as s')
                                ->leftJoin('t_receipts as r', function ($j) {
                                    $j->on('s.family_id', '=', 'r.family_id')
                                    ->on('s.year', '=', 'r.year')
                                    ->where('r.status', 'active');
                                })
                                ->select('s.family_id')
                                ->groupBy('s.family_id', 's.year')
                                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)');
                        });
                    }
                }

                $rows = $q->get();

                if ($rows->isEmpty()) {
                    return $this->error('No data found for export.', 404);
                }

                $familyIds = $rows->pluck('family_id')->unique()->all();
                $dueService = app(DueCalculationService::class);
                $familyDueBulk = $dueService->getFamilyDueBulk($familyIds, $currentYearStr);

                $excelRows = [];
                $sn = 1;
                $totalSabeel = 0;
                $totalPaid   = 0;
                $totalDue    = 0;

                if ($useSpecificYear) {
                    $sabeelMap = DB::table('t_mumineen_sabeel')
                        ->whereIn('family_id', $familyIds)
                        ->where('year', $targetYear)
                        ->pluck('sabeel', 'family_id');

                    $paidMap = DB::table('t_receipts')
                        ->whereIn('family_id', $familyIds)
                        ->where('year', $targetYear)
                        ->where('status', 'active')
                        ->groupBy('family_id')
                        ->select('family_id', DB::raw('SUM(amount) as paid'))
                        ->pluck('paid', 'family_id');

                    $dueBulkForYear = $dueService->getFamilyDueBulk($familyIds, $targetYear);

                    foreach ($rows as $m) {
                        $sabeel = (float) ($sabeelMap[$m->family_id] ?? 0);
                        $paid   = (float) ($paidMap[$m->family_id] ?? 0);
                        $famDue = $dueBulkForYear[(int)$m->family_id] ?? null;
                        $due    = $famDue ? $famDue['due_effective'] : max(0, $sabeel - $paid);

                        if ($filter === 'due_family' && $due <= 0) {
                            continue;
                        }

                        $totalSabeel += $sabeel;
                        $totalPaid   += $paid;
                        $totalDue    += $due;

                        $excelRows[] = [
                            $sn++,
                            $m->its,
                            $m->name,
                            $m->mobile ?? '-',
                            $m->email ?? '-',
                            $m->sector ?? '-',
                            $targetYear,
                            $sabeel,
                            $paid,
                            $due,
                        ];
                    }
                } else {
                    // All years export - show one row per family-year combination
                    $sabeelData = DB::table('t_mumineen_sabeel')
                        ->whereIn('family_id', $familyIds)
                        ->select('family_id', 'year', 'sabeel')
                        ->get()
                        ->groupBy('family_id');

                    $paidData = DB::table('t_receipts')
                        ->whereIn('family_id', $familyIds)
                        ->where('status', 'active')
                        ->select('family_id', 'year', DB::raw('SUM(amount) as paid'))
                        ->groupBy('family_id', 'year')
                        ->get()
                        ->groupBy('family_id');

                    foreach ($rows as $m) {
                        $familySabeels = $sabeelData->get($m->family_id, collect());
                        $familyPaids = $paidData->get($m->family_id, collect());

                        // Get all unique years for this family
                        $years = $familySabeels->pluck('year')->merge($familyPaids->pluck('year'))->unique()->sort();

                        foreach ($years as $yr) {
                            $sabeelEntry = $familySabeels->firstWhere('year', $yr);
                            $paidEntry = $familyPaids->firstWhere('year', $yr);

                            $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                            $paid   = (float) ($paidEntry->paid ?? 0);
                            $due    = max(0, $sabeel - $paid);
                            if ($yr === $currentYearStr) {
                                $famDue = $familyDueBulk[(int)$m->family_id] ?? null;
                                if ($famDue) {
                                    $due = $famDue['due_effective'];
                                }
                            }

                            if ($filter === 'due_family' && $due <= 0) {
                                continue;
                            }

                            $totalSabeel += $sabeel;
                            $totalPaid   += $paid;
                            $totalDue    += $due;

                            $excelRows[] = [
                                $sn++,
                                $m->its,
                                $m->name,
                                $m->mobile ?? '-',
                                $m->email ?? '-',
                                $m->sector ?? '-',
                                $yr,
                                $sabeel,
                                $paid,
                                $due,
                            ];
                        }
                    }
                }

                // ✅ Add TOTAL row at bottom
                $excelRows[] = [
                    '', '', '', '', '', '',
                    'TOTAL',
                    $totalSabeel,
                    $totalPaid,
                    $totalDue,
                ];

                $export = new GenericExcelExport(
                    $excelRows,
                    ['SN','ITS','Name','Mobile','Email','Sector','Year','Sabeel','Paid','Due'],
                    [
                        'A' => Alignment::HORIZONTAL_CENTER,
                        'B' => Alignment::HORIZONTAL_CENTER,
                        'C' => Alignment::HORIZONTAL_LEFT,
                        'D' => Alignment::HORIZONTAL_CENTER,
                        'E' => Alignment::HORIZONTAL_LEFT,
                        'F' => Alignment::HORIZONTAL_CENTER,
                        'G' => Alignment::HORIZONTAL_CENTER,
                        'H' => Alignment::HORIZONTAL_RIGHT,
                        'I' => Alignment::HORIZONTAL_RIGHT,
                        'J' => Alignment::HORIZONTAL_RIGHT,
                    ]
                );

                return ExcelExportHelper::store($export, 'dashboard', "dashboard_{$filter}");
            }

            /* ============================================================
            ESTABLISHMENT EXPORT
            ============================================================ */
            // Base establishment query
            $q = EstablishmentModel::query()->orderBy('name', 'asc');

            // due_establishment -> only those having due (paid < sabeel)
            if ($filter === 'due_establishment') {
                if ($useSpecificYear) {
                    // Optimized query for specific year
                    $q->whereIn('establishment_id', function ($sub) use ($targetYear) {
                        $sub->select('s.establishment_id')
                            ->from('t_establishment_sabeel as s')
                            ->leftJoin('t_receipts as r', function ($j) use ($targetYear) {
                                $j->on('s.establishment_id', '=', 'r.establishment_id')
                                    ->where('r.status', 'active')
                                    ->where('r.year', $targetYear);
                            })
                            ->where('s.year', $targetYear)
                            ->groupBy('s.establishment_id')
                            ->havingRaw('COALESCE(SUM(r.amount), 0) < MAX(s.sabeel)');
                    });
                    $rows = $q->get();
                } else {
                    // For all years - optimized approach: get all establishments first, then filter
                    // This is faster than the complex subquery with groupBy on both columns
                    $allEstIds = DB::table('t_establishment')
                        ->pluck('establishment_id')
                        ->all();
                    
                    if (empty($allEstIds)) {
                        $rows = collect();
                    } else {
                        // Get sabeel data grouped by establishment_id and year
                        $sabeelData = DB::table('t_establishment_sabeel')
                            ->whereIn('establishment_id', $allEstIds)
                            ->select('establishment_id', 'year', 'sabeel')
                            ->get()
                            ->groupBy('establishment_id');
                        
                        // Get paid data grouped by establishment_id and year
                        $paidData = DB::table('t_receipts')
                            ->whereIn('establishment_id', $allEstIds)
                            ->where('status', 'active')
                            ->select('establishment_id', 'year', DB::raw('SUM(amount) as paid'))
                            ->groupBy('establishment_id', 'year')
                            ->get()
                            ->groupBy('establishment_id');
                        
                        // Find establishments with any year having due
                        $estIdsWithDue = [];
                        foreach ($allEstIds as $estId) {
                            $estSabeels = $sabeelData->get($estId, collect());
                            $estPaids = $paidData->get($estId, collect());
                            
                            // Get all unique years for this establishment
                            $years = $estSabeels->pluck('year')->merge($estPaids->pluck('year'))->unique();
                            
                            foreach ($years as $yr) {
                                $sabeelEntry = $estSabeels->firstWhere('year', $yr);
                                $paidEntry = $estPaids->firstWhere('year', $yr);
                                
                                $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                                $paid = (float) ($paidEntry->paid ?? 0);
                                $due = max(0, $sabeel - $paid);
                                
                                if ($due > 0) {
                                    $estIdsWithDue[] = $estId;
                                    break; // Found due for this establishment, no need to check other years
                                }
                            }
                        }
                        
                        if (empty($estIdsWithDue)) {
                            $rows = collect();
                        } else {
                            $q->whereIn('establishment_id', $estIdsWithDue);
                            $rows = $q->get();
                        }
                    }
                }
            } else {
                // For 'establishment' filter (no due filter), execute query normally
                $rows = $q->get();
            }

            if ($rows->isEmpty()) {
                return $this->error('No data found for export.', 404);
            }

            $estIds = $rows->pluck('establishment_id')->all();
            $dueServiceEst = app(DueCalculationService::class);
            $estDueBulk = $dueServiceEst->getEstablishmentDueBulk($estIds, $currentYearStr);

            // Partner links
            $links = MumineenEstablishmentModel::whereIn('establishment_id', $estIds)->get();
            $familyIds = $links->pluck('family_id')->unique()->all();

            $hofs = MumineenModel::whereIn('family_id', $familyIds)
                ->where('hof_type', 'HOF')
                ->get()
                ->keyBy('family_id');

            $partnersByEst = [];
            foreach ($links as $l) {
                if (!isset($hofs[$l->family_id])) continue;
                $partnersByEst[$l->establishment_id][] = $hofs[$l->family_id]->name;
            }

            $excelRows = [];
            $sn = 1;
            $totalSabeel = 0;
            $totalPaid   = 0;
            $totalDue    = 0;

            if ($useSpecificYear) {
                $sabeelMap = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
                    ->where('year', $targetYear)
                    ->pluck('sabeel', 'establishment_id');

                $paidMap = DB::table('t_receipts')
                    ->whereIn('establishment_id', $estIds)
                    ->where('year', $targetYear)
                    ->where('status', 'active')
                    ->groupBy('establishment_id')
                    ->select('establishment_id', DB::raw('SUM(amount) as paid'))
                    ->pluck('paid', 'establishment_id');

                $estDueBulkForYear = $dueServiceEst->getEstablishmentDueBulk($estIds, $targetYear);

                foreach ($rows as $e) {
                    $eid = $e->establishment_id;
                    $sabeel = (float) ($sabeelMap[$eid] ?? 0);
                    $paid   = (float) ($paidMap[$eid] ?? 0);
                    $eDue = $estDueBulkForYear[$eid] ?? null;
                    $due = $eDue ? $eDue['due_effective'] : max(0, $sabeel - $paid);

                    $totalSabeel += $sabeel;
                    $totalPaid   += $paid;
                    $totalDue    += $due;

                    $excelRows[] = [
                        $sn++,
                        $e->name,
                        '-',
                        '-',
                        $e->address ?? '-',
                        isset($partnersByEst[$eid]) ? implode(', ', $partnersByEst[$eid]) : '-',
                        $targetYear,
                        $sabeel,
                        $paid,
                        $due,
                    ];
                }
            } else {
                // All years export - show one row per establishment-year combination
                $sabeelData = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
                    ->select('establishment_id', 'year', 'sabeel')
                    ->get()
                    ->groupBy('establishment_id');

                $paidData = DB::table('t_receipts')
                    ->whereIn('establishment_id', $estIds)
                    ->where('status', 'active')
                    ->select('establishment_id', 'year', DB::raw('SUM(amount) as paid'))
                    ->groupBy('establishment_id', 'year')
                    ->get()
                    ->groupBy('establishment_id');

                foreach ($rows as $e) {
                    $estSabeels = $sabeelData->get($e->establishment_id, collect());
                    $estPaids = $paidData->get($e->establishment_id, collect());

                    // Get all unique years for this establishment
                    $years = $estSabeels->pluck('year')->merge($estPaids->pluck('year'))->unique()->sort();

                    foreach ($years as $yr) {
                        $sabeelEntry = $estSabeels->firstWhere('year', $yr);
                        $paidEntry = $estPaids->firstWhere('year', $yr);

                        $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                        $paid   = (float) ($paidEntry->paid ?? 0);
                        $due    = max(0, $sabeel - $paid);
                        if ($yr === $currentYearStr) {
                            $eDue = $estDueBulk[$e->establishment_id] ?? null;
                            if ($eDue) {
                                $due = $eDue['due_effective'];
                            }
                        }

                        $totalSabeel += $sabeel;
                        $totalPaid   += $paid;
                        $totalDue    += $due;

                        $excelRows[] = [
                            $sn++,
                            $e->name,
                            '-',
                            '-',
                            $e->address ?? '-',
                            isset($partnersByEst[$e->establishment_id])
                                ? implode(', ', $partnersByEst[$e->establishment_id])
                                : '-',
                            $yr,
                            $sabeel,
                            $paid,
                            $due,
                        ];
                    }
                }
            }

            // ✅ Add TOTAL row at bottom
            $excelRows[] = [
                '', '', '', '', '', '',
                'TOTAL',
                $totalSabeel,
                $totalPaid,
                $totalDue,
            ];

            $export = new GenericExcelExport(
                $excelRows,
                ['SN','Name','Mobile','Email','Address','Partners','Year','Sabeel','Paid','Due'],
                [
                    'A' => Alignment::HORIZONTAL_CENTER,
                    'B' => Alignment::HORIZONTAL_LEFT,
                    'C' => Alignment::HORIZONTAL_CENTER,
                    'D' => Alignment::HORIZONTAL_LEFT,
                    'E' => Alignment::HORIZONTAL_LEFT,
                    'F' => Alignment::HORIZONTAL_LEFT,
                    'G' => Alignment::HORIZONTAL_CENTER,
                    'H' => Alignment::HORIZONTAL_RIGHT,
                    'I' => Alignment::HORIZONTAL_RIGHT,
                    'J' => Alignment::HORIZONTAL_RIGHT,
                ]
            );

            return ExcelExportHelper::store($export, 'dashboard', "dashboard_{$filter}");

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment export failed');
        }
    }

    // helper
    private function resolveYears(): array
    {
        // Safe fallback if t_year table does not exist
        if (!Schema::hasTable('t_year')) {
            $current = (int) date('Y');
            return [$current, $current - 1];
        }

        // Try current year flag
        $current = (int) DB::table('t_year')
            ->where('is_current', 1)
            ->value('year');

        // Fallbacks
        if (!$current) {
            $current = (int) DB::table('t_year')->max('year');
        }

        if (!$current) {
            $current = (int) date('Y');
        }

        // Previous year
        $previous = (int) DB::table('t_year')
            ->where('year', '<', $current)
            ->max('year');

        if (!$previous) {
            $previous = $current - 1;
        }

        return [$current, $previous];
    }

    private function applyFilter(Builder $q, string $filter, $currentYear, $prevYear): Builder
    {
        $filter = strtolower(trim($filter));

        // Apply only establishment-related filters here
        switch ($filter) {
            case 'active':
                return $q->where('status', 'active');

            case 'inactive':
                return $q->where('status', 'inactive');

            // Example: establishments that have sabeel > 0 for current year
            case 'with_sabeel':
                return $q->whereExists(function ($sub) use ($currentYear) {
                    $sub->from('t_establishment_sabeel as es')
                        ->whereColumn('es.establishment_id', 't_establishment.establishment_id')
                        ->where('es.year', $currentYear)
                        ->where('es.sabeel', '>', 0);
                });

            // Example: establishments that have NO sabeel > 0 for current year
            case 'no_sabeel':
                return $q->whereNotExists(function ($sub) use ($currentYear) {
                    $sub->from('t_establishment_sabeel as es')
                        ->whereColumn('es.establishment_id', 't_establishment.establishment_id')
                        ->where('es.year', $currentYear)
                        ->where('es.sabeel', '>', 0);
                });

            default:
                return $q; // unknown filter -> no changes
        }
    }
}
