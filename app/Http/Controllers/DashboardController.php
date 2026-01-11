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

            $totalHouses = MumineenModel::where('status', 'active')->distinct('family_id')->count('family_id');
            
            $externalHouses = MumineenModel::where('status', 'active')
                ->where('external', true)
                ->distinct('family_id')
                ->count('family_id');

            $totalMumineenSabeel = MumineenSabeelModel::sum('sabeel');

            $paidMumineen = ReceiptModel::whereNotNull('family_id')
                ->where('status', 'active')
                ->sum('amount');

            $dueMumineenSabeel = max(0, $totalMumineenSabeel - $paidMumineen);

            $dueHouses = DB::table('t_mumineen_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.family_id', '=', 'r.family_id')
                    ->where('r.status', 'active');
                })
                ->select(
                    's.family_id',
                    DB::raw('SUM(r.amount) as paid'),
                    DB::raw('MAX(s.sabeel) as sabeel')
                )
                ->groupBy('s.family_id')
                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)')
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

            $establishmentMissing = MumineenModel::whereNotIn('family_id', function ($q) {
                    $q->select('family_id')
                      ->from('t_mumineen_establishment');
                })->count();

            /* ================= ESTABLISHMENT ================= */

            $totalEstablishment = EstablishmentModel::count();

            $totalEstSabeel = EstablishmentSabeelModel::sum('sabeel');

            $paidEst = ReceiptModel::whereNotNull('establishment_id')
                ->where('status', 'active')
                ->sum('amount');

            $dueEstSabeel = max(0, $totalEstSabeel - $paidEst);

            $dueEstablishment = DB::table('t_establishment_sabeel as s')
                ->leftJoin('t_receipts as r', function ($q) {
                    $q->on('s.establishment_id', '=', 'r.establishment_id')
                    ->where('r.status', 'active');
                })
                ->select(
                    's.establishment_id',
                    DB::raw('SUM(r.amount) as paid'),
                    DB::raw('MAX(s.sabeel) as sabeel')
                )
                ->groupBy('s.establishment_id')
                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)')
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
                    'service'              => '',
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

            if ($request->type === 'sabeel') {
                $rows = DB::table('t_mumineen_sabeel as s')
                    ->leftJoin('t_receipts as r', function ($q) {
                        $q->on('s.family_id', '=', 'r.family_id')
                        ->where('r.status', 'active');
                    })
                    ->select(
                        's.year',
                        DB::raw('GREATEST(SUM(s.sabeel) - COALESCE(SUM(r.amount),0),0) as due')
                    )
                    ->groupBy('s.year')
                    ->orderBy('s.year', 'desc')
                    ->get();
            } else {
                $rows = DB::table('t_establishment_sabeel as s')
                    ->leftJoin('t_receipts as r', function ($q) {
                        $q->on('s.establishment_id', '=', 'r.establishment_id')
                        ->where('r.status', 'active');
                    })
                    ->select(
                        's.year',
                        DB::raw('GREATEST(SUM(s.sabeel) - COALESCE(SUM(r.amount),0),0) as due')
                    )
                    ->groupBy('s.year')
                    ->orderBy('s.year', 'desc')
                    ->get();
            }

            $data = $rows->map(fn ($r) => [
                'year' => $r->year,
                'due'  => (string) $r->due,
            ]);

            return $this->success('Sabeel due fetched', $data, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Sabeel due fetch failed');
        }
    }

    public function exportEstablishment(Request $request)
    {
        try {
            $currentYearStr = $this->getCurrentYear();

            $filter = trim((string) $request->input('filter', ''));

            if (!in_array($filter, ['family','due_family','establishment','due_establishment'], true)) {
                return $this->error('Invalid filter. Allowed: family, due_family, establishment, due_establishment', 422);
            }

            /* ============================================================
            FAMILY EXPORT
            ============================================================ */
            if ($filter === 'family' || $filter === 'due_family') {

                // Base: all HOF
                $q = MumineenModel::query()
                    ->where('hof_type', 'HOF')
                    ->select('id','family_id','its','name','sector','mobile','email')
                    ->orderBy('name', 'asc');

                // If due_family -> only those having due (paid < sabeel)
                if ($filter === 'due_family') {
                    $q->whereIn('family_id', function ($sub) use ($currentYearStr) {
                        $sub->from('t_mumineen_sabeel as s')
                            ->leftJoin('t_receipts as r', function ($j) {
                                $j->on('s.family_id', '=', 'r.family_id')
                                ->where('r.status', 'active');
                            })
                            ->where('s.year', $currentYearStr)
                            ->select('s.family_id')
                            ->groupBy('s.family_id')
                            ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)');
                    });
                }

                $rows = $q->get();

                if ($rows->isEmpty()) {
                    return $this->error('No data found for export.', 404);
                }

                // preload sabeel map (current year)
                $familyIds = $rows->pluck('family_id')->unique()->all();

                $sabeelMap = DB::table('t_mumineen_sabeel')
                    ->whereIn('family_id', $familyIds)
                    ->where('year', $currentYearStr)
                    ->pluck('sabeel', 'family_id');

                // paid map (active receipts)
                $paidMap = DB::table('t_receipts')
                    ->whereIn('family_id', $familyIds)
                    ->where('status', 'active')
                    ->groupBy('family_id')
                    ->select('family_id', DB::raw('SUM(amount) as paid'))
                    ->pluck('paid', 'family_id');

                $excelRows = [];
                $sn = 1;

                $totalSabeel = 0;
                $totalPaid   = 0;
                $totalDue    = 0;

                foreach ($rows as $m) {
                    $sabeel = (float) ($sabeelMap[$m->family_id] ?? 0);
                    $paid   = (float) ($paidMap[$m->family_id] ?? 0);
                    $due    = max(0, $sabeel - $paid);

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
                        $sabeel,
                        $paid,
                        $due,
                    ];
                }

                // ✅ Add TOTAL row at bottom
                $excelRows[] = [
                    '', '', '', '', '',
                    'TOTAL',               // left box before Sabeel/Paid/Due
                    $totalSabeel,
                    $totalPaid,
                    $totalDue,
                ];

                $export = new GenericExcelExport(
                    $excelRows,
                    ['SN','ITS','Name','Mobile','Email','Sector','Sabeel','Paid','Due'],
                    [
                        'A' => Alignment::HORIZONTAL_CENTER,
                        'B' => Alignment::HORIZONTAL_CENTER,
                        'C' => Alignment::HORIZONTAL_LEFT,
                        'D' => Alignment::HORIZONTAL_CENTER,
                        'E' => Alignment::HORIZONTAL_LEFT,
                        'F' => Alignment::HORIZONTAL_CENTER,
                        'G' => Alignment::HORIZONTAL_RIGHT,
                        'H' => Alignment::HORIZONTAL_RIGHT,
                        'I' => Alignment::HORIZONTAL_RIGHT,
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
                $q->whereIn('establishment_id', function ($sub) use ($currentYearStr) {
                    $sub->from('t_establishment_sabeel as s')
                        ->leftJoin('t_receipts as r', function ($j) {
                            $j->on('s.establishment_id', '=', 'r.establishment_id')
                            ->where('r.status', 'active');
                        })
                        ->where('s.year', $currentYearStr)
                        ->select('s.establishment_id')
                        ->groupBy('s.establishment_id')
                        ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)');
                });
            }

            $rows = $q->get();

            if ($rows->isEmpty()) {
                return $this->error('No data found for export.', 404);
            }

            // preload data like your old code
            $estIds = $rows->pluck('establishment_id')->all();

            $sabeelMap = EstablishmentSabeelModel::whereIn('establishment_id', $estIds)
                ->where('year', $currentYearStr)
                ->pluck('sabeel', 'establishment_id');

            // paid map for establishments
            $paidMap = DB::table('t_receipts')
                ->whereIn('establishment_id', $estIds)
                ->where('status', 'active')
                ->groupBy('establishment_id')
                ->select('establishment_id', DB::raw('SUM(amount) as paid'))
                ->pluck('paid', 'establishment_id');

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
                    $sabeel,
                    $paid,
                    $due,
                ];
            }

            // ✅ Add TOTAL row at bottom
            $excelRows[] = [
                '', '', '', '', '',
                'TOTAL',               // left box before Sabeel/Paid/Due
                $totalSabeel,
                $totalPaid,
                $totalDue,
            ];

            $export = new GenericExcelExport(
                $excelRows,
                ['SN','Name','Mobile','Email','Address','Partners','Sabeel','Paid','Due'],
                [
                    'A' => Alignment::HORIZONTAL_CENTER,
                    'B' => Alignment::HORIZONTAL_LEFT,
                    'C' => Alignment::HORIZONTAL_CENTER,
                    'D' => Alignment::HORIZONTAL_LEFT,
                    'E' => Alignment::HORIZONTAL_LEFT,
                    'F' => Alignment::HORIZONTAL_LEFT,
                    'G' => Alignment::HORIZONTAL_RIGHT,
                    'H' => Alignment::HORIZONTAL_RIGHT,
                    'I' => Alignment::HORIZONTAL_RIGHT,
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
