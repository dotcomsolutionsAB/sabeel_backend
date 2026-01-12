<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;

// ... same imports

class EstablishmentSabeelController extends Controller
{
    use ApiResponse;

    public function create(Request $request, $establishment_id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|string|max:10',
                'amount' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) return $this->validation($validator);

            $exists = EstablishmentSabeelModel::where('establishment_id', $establishment_id)
                ->where('year', $request->year)
                ->exists();

            if ($exists) {
                return $this->error('Sabeel already exists for this establishment and year.', 409);
            }

            EstablishmentSabeelModel::create([
                'establishment_id' => (int) $establishment_id,
                'year'             => $request->year,
                'sabeel'           => (int) $request->amount,
                'updated_by'       => (int) Auth::id(),
            ]);

            $payload = $this->buildEstablishmentSummaryPayload($establishment_id);
            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel create failed');
        }
    }

    public function fetch(Request $request, $establishment_id, $id = null)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $payload = $this->buildEstablishmentSummaryPayload($establishment_id);

            // 👇 wrapping in array is IMPORTANT
            return $this->success('Data fetched successfully', [$payload], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel fetch failed');
        }
    }

    /**
     * UPDATE by establishment_id and year
     * POST /establishment_sabeel/update/{establishment_id}
     * Body: { "year": "2024-25", "sabeel": 6000 }
     */
    public function update(Request $request, $establishment_id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|string|max:10',
                'sabeel' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $year = $request->year;
            $sabeel = (float) $request->sabeel;

            // Find sabeel entry by establishment_id and year
            $row = EstablishmentSabeelModel::where('establishment_id', $establishment_id)
                ->where('year', $year)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found for this establishment and year.', 404);
            }

            // Validation: sabeel cannot be less than amount already paid in receipts for that year
            $paidThisYear = (float) ReceiptModel::where('establishment_id', $establishment_id)
                ->where('year', $year)
                ->where('status', 'active')
                ->sum('amount');

            if ($sabeel < $paidThisYear) {
                return $this->error("Sabeel cannot be less than the amount already paid ({$paidThisYear}) for this year.", 422);
            }

            // Update the sabeel entry
            $row->sabeel = (int) $sabeel;
            $row->updated_by = (int) Auth::id();
            $row->save();

            $payload = $this->buildEstablishmentSummaryPayload($establishment_id);

            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel update failed');
        }
    }

    public function edit(Request $request, $establishment_id, $id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_id', $establishment_id)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|string|max:10',
                'amount' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) return $this->validation($validator);

            $dup = EstablishmentSabeelModel::where('establishment_id', $establishment_id)
                ->where('year', $request->year)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($dup) {
                return $this->error('Another entry already exists for this year.', 409);
            }

            $row->year = $request->year;
            $row->sabeel = (int) $request->amount;
            $row->updated_by = (int) Auth::id();
            $row->save();

            $payload = $this->buildEstablishmentSummaryPayload($establishment_id);
            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel update failed');
        }
    }

    public function delete($establishment_id, $id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_id', $establishment_id)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found.', 404);
            }

            $row->delete();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel delete failed');
        }
    }

    private function resolveEstablishment($establishment_id): ?EstablishmentModel
    {
        return EstablishmentModel::where('establishment_id', $establishment_id)->first();
    }

    /**
     * Build establishment sabeel summary payload
     * Uses PRIMARY KEY id internally
     */
    private function buildEstablishmentSummaryPayload(int $establishment_id): array
    {
        $rows = EstablishmentSabeelModel::where('establishment_id', $establishment_id)
            ->orderBy('year', 'desc')
            ->get();

        $details = $rows->map(function ($r) use ($establishment_id) {

            $paid = $this->paidForYear($establishment_id, (int)$r->year);
            $due  = max(0, (int)$r->sabeel - $paid);

            return [
                'year'   => $this->formatFinancialYear((int)$r->year),
                'sabeel' => (string) $r->sabeel,
                'due'    => (string) $due,
            ];
        })->values();

        // 👇 THIS SHAPE IS THE KEY
        return [
            'sabeel_details' => $details,
        ];
    }

    private function formatFinancialYear(int $year): string
    {
        return $year . '-' . substr((string)($year + 1), -2);
    }

    private function paidForYear(int $establishment_id, int $year): int
    {
        return (int) ReceiptModel::where('establishment_id', $establishment_id)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');
    }
}
