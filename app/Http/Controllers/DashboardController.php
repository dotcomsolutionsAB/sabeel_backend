<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
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

            // Calculate total sabeel for active HOFs only
            $totalMumineenSabeel = DB::table('t_mumineen_sabeel')
                ->whereIn('family_id', $activeHofFamilyIds)
                ->sum('sabeel');

            // Calculate paid amount for active HOFs only (year-wise matching)
            $paidMumineen = DB::table('t_mumineen_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.family_id', '=', 'r.family_id')
                    ->on('s.year', '=', 'r.year')
                    ->where('r.status', 'active');
                })
                ->whereIn('s.family_id', $activeHofFamilyIds)
                ->select(DB::raw('COALESCE(SUM(r.amount),0) as paid'))
                ->value('paid') ?? 0;

            $dueMumineenSabeel = max(0, $totalMumineenSabeel - $paidMumineen);

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

            // Calculate total sabeel for all establishments
            $totalEstSabeel = EstablishmentSabeelModel::sum('sabeel');

            // Calculate paid amount for all establishments (year-wise matching)
            $paidEst = DB::table('t_establishment_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.establishment_id', '=', 'r.establishment_id')
                    ->on('s.year', '=', 'r.year')
                    ->where('r.status', 'active');
                })
                ->whereIn('s.establishment_id', $allEstablishmentIds)
                ->select(DB::raw('COALESCE(SUM(r.amount),0) as paid'))
                ->value('paid') ?? 0;

            $dueEstSabeel = max(0, $totalEstSabeel - $paidEst);

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
            return $this->serverError($e, 'Dashboard retrieve failed');
        }
    }

    public function retrieveSabeelDue(Request $request)
    {
        try {
            $request->validate([
                'type' => 'required|in:sabeel,establishment',
            ]);

            $yearDueMap = [];

            if ($request->type === 'sabeel') {
                /* ============================================================
                FAMILY DUE CALCULATION
                ============================================================ */
                // Base: all active HOF
                $hofs = MumineenModel::query()
                    ->where('hof_type', 'HOF')
                    ->where('status', 'active')
                    ->select('family_id')
                    ->get();

                $familyIds = $hofs->pluck('family_id')->unique()->all();

                if (!empty($familyIds)) {
                    // Get all sabeel data grouped by family_id and year
                    $sabeelData = DB::table('t_mumineen_sabeel')
                        ->whereIn('family_id', $familyIds)
                        ->select('family_id', 'year', 'sabeel')
                        ->get()
                        ->groupBy('family_id');

                    // Get all paid data grouped by family_id and year
                    $paidData = DB::table('t_receipts')
                        ->whereIn('family_id', $familyIds)
                        ->where('status', 'active')
                        ->select('family_id', 'year', DB::raw('SUM(amount) as paid'))
                        ->groupBy('family_id', 'year')
                        ->get()
                        ->groupBy('family_id');

                    // Calculate due per year for families
                    foreach ($familyIds as $familyId) {
                        $familySabeels = $sabeelData->get($familyId, collect());
                        $familyPaids = $paidData->get($familyId, collect());

                        // Get all unique years for this family
                        $years = $familySabeels->pluck('year')->merge($familyPaids->pluck('year'))->unique()->sort();

                        foreach ($years as $yr) {
                            $sabeelEntry = $familySabeels->firstWhere('year', $yr);
                            $paidEntry = $familyPaids->firstWhere('year', $yr);

                            $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                            $paid   = (float) ($paidEntry->paid ?? 0);
                            $due    = max(0, $sabeel - $paid);

                            if (!isset($yearDueMap[$yr])) {
                                $yearDueMap[$yr] = 0;
                            }
                            $yearDueMap[$yr] += $due;
                        }
                    }
                }
            } else {
                /* ============================================================
                ESTABLISHMENT DUE CALCULATION
                ============================================================ */
                // Base establishment query
                $establishments = EstablishmentModel::query()
                    ->select('establishment_id')
                    ->get();

                $estIds = $establishments->pluck('establishment_id')->all();

                if (!empty($estIds)) {
                    // Get all sabeel data grouped by establishment_id and year
                    $sabeelData = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
                        ->select('establishment_id', 'year', 'sabeel')
                        ->get()
                        ->groupBy('establishment_id');

                    // Get all paid data grouped by establishment_id and year
                    $paidData = DB::table('t_receipts')
                        ->whereIn('establishment_id', $estIds)
                        ->where('status', 'active')
                        ->select('establishment_id', 'year', DB::raw('SUM(amount) as paid'))
                        ->groupBy('establishment_id', 'year')
                        ->get()
                        ->groupBy('establishment_id');

                    // Calculate due per year for establishments
                    foreach ($estIds as $estId) {
                        $estSabeels = $sabeelData->get($estId, collect());
                        $estPaids = $paidData->get($estId, collect());

                        // Get all unique years for this establishment
                        $years = $estSabeels->pluck('year')->merge($estPaids->pluck('year'))->unique()->sort();

                        foreach ($years as $yr) {
                            $sabeelEntry = $estSabeels->firstWhere('year', $yr);
                            $paidEntry = $estPaids->firstWhere('year', $yr);

                            $sabeel = (float) ($sabeelEntry->sabeel ?? 0);
                            $paid   = (float) ($paidEntry->paid ?? 0);
                            $due    = max(0, $sabeel - $paid);

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

                $excelRows = [];
                $sn = 1;
                $totalSabeel = 0;
                $totalPaid   = 0;
                $totalDue    = 0;

                if ($useSpecificYear) {
                    // Single year export
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

                    foreach ($rows as $m) {
                        $sabeel = (float) ($sabeelMap[$m->family_id] ?? 0);
                        $paid   = (float) ($paidMap[$m->family_id] ?? 0);
                        $due    = max(0, $sabeel - $paid);

                        // If filter is due_family, only export rows with due > 0
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

                            // If filter is due_family, only export rows with due > 0
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
                    $q->whereIn('establishment_id', function ($sub) use ($targetYear) {
                        $sub->from('t_establishment_sabeel as s')
                            ->leftJoin('t_receipts as r', function ($j) use ($targetYear) {
                                $j->on('s.establishment_id', '=', 'r.establishment_id')
                                ->where('r.status', 'active')
                                ->where('r.year', $targetYear);
                            })
                            ->where('s.year', $targetYear)
                            ->select('s.establishment_id')
                            ->groupBy('s.establishment_id')
                            ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)');
                    });
                } else {
                    // For all years, check if any year has due
                    $q->whereIn('establishment_id', function ($sub) {
                        $sub->from('t_establishment_sabeel as s')
                            ->leftJoin('t_receipts as r', function ($j) {
                                $j->on('s.establishment_id', '=', 'r.establishment_id')
                                ->on('s.year', '=', 'r.year')
                                ->where('r.status', 'active');
                            })
                            ->select('s.establishment_id')
                            ->groupBy('s.establishment_id', 's.year')
                            ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)');
                    });
                }
            }

            $rows = $q->get();

            if ($rows->isEmpty()) {
                return $this->error('No data found for export.', 404);
            }

            $estIds = $rows->pluck('establishment_id')->all();

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
                // Single year export
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

                foreach ($rows as $e) {
                    $sabeel = (float) ($sabeelMap[$e->establishment_id] ?? 0);
                    $paid   = (float) ($paidMap[$e->establishment_id] ?? 0);
                    $due    = max(0, $sabeel - $paid);

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
