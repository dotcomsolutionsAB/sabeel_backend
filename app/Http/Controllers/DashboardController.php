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
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\ReceiptModel;

class DashboardController extends Controller
{
    //
    use ApiResponse;

    public function retrieve()
    {
        try {
            $currentYear = now()->year;

            /* ================= MUMINEEN ================= */

            $totalHouses = MumineenModel::distinct('family_id')->count('family_id');

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
                ->where('s.year', '<', $currentYear)
                ->select(
                    's.family_id',
                    DB::raw('SUM(r.amount) as paid'),
                    DB::raw('MAX(s.sabeel) as sabeel')
                )
                ->groupBy('s.family_id')
                ->havingRaw('COALESCE(SUM(r.amount),0) < MAX(s.sabeel)')
                ->count();

            $newTakhmeenPending = MumineenModel::whereNotIn('family_id', function ($q) use ($currentYear) {
                    $q->select('family_id')
                      ->from('t_mumineen_sabeel')
                      ->where('year', $currentYear);
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
                'year' => $r->year . '-' . substr((string)($r->year + 1), -2),
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
            [$currentYear, $prevYear] = $this->resolveYears();

            $limit  = max(1, (int) $request->input('limit', 50));
            $offset = max(0, (int) $request->input('offset', 0));

            $q = EstablishmentModel::query()
                ->orderBy('name', 'asc');

            if ($request->filled('filter')) {
                $q = $this->applyFilter($q, $request->filter, $currentYear, $prevYear);
            }

            $rows = $q
                ->skip($offset)
                ->take($limit)
                ->get();

            $excelRows = [];
            $sn = $offset + 1; // 👈 SN must respect pagination

            foreach ($rows as $e) {
                $excelRows[] = [
                    $sn++,
                    $e->name,
                    '',
                    '',
                    $e->address,
                    '',
                    0,
                ];
            }

            $export = new GenericExcelExport(
                $excelRows,
                ['SN','Name','Mobile','Email','Address','Partners','Sabeel'],
                [
                    'G' => '_₹* #,##0.00_ ;_₹* (#,##0.00);_₹* "-"??_ ;_@_ '
                ],
                [
                    'A' => Alignment::HORIZONTAL_CENTER,
                    'B' => Alignment::HORIZONTAL_LEFT,
                    'C' => Alignment::HORIZONTAL_CENTER,
                    'D' => Alignment::HORIZONTAL_LEFT,
                    'E' => Alignment::HORIZONTAL_LEFT,
                    'F' => Alignment::HORIZONTAL_LEFT,
                    'G' => Alignment::HORIZONTAL_RIGHT,
                ]
            );

            return ExcelExportHelper::store(
                $export,
                'dashboard',
                'dashboard_establishment'
            );

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
}
