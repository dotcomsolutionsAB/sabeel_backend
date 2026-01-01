<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
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

            $havingPrevDue = MumineenSabeelModel::leftJoin('t_receipts as r', function ($q) {
                    $q->on('t_mumineen_sabeel.family_id', '=', 'r.family_id')
                      ->where('r.status', 'active');
                })
                ->where('t_mumineen_sabeel.year', '<', $currentYear)
                ->groupBy('t_mumineen_sabeel.family_id', 't_mumineen_sabeel.sabeel')
                ->havingRaw('COALESCE(SUM(r.amount),0) < t_mumineen_sabeel.sabeel')
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

            $dueEstablishment = EstablishmentSabeelModel::leftJoin('t_receipts as r', function ($q) {
                    $q->on('t_establishment_sabeel.establishment_id', '=', 'r.establishment_id')
                      ->where('r.status', 'active');
                })
                ->groupBy('t_establishment_sabeel.establishment_id', 't_establishment_sabeel.sabeel')
                ->havingRaw('COALESCE(SUM(r.amount),0) < t_establishment_sabeel.sabeel')
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
}
