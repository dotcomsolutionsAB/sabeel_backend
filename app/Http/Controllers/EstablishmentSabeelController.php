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

    public function create(Request $request, $establishment_no)
    {
        try {
            $est = $this->resolveEstablishment($establishment_no);
            if (!$est) {
                return $this->error('Invalid establishment_no. Establishment not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) return $this->validation($validator);

            $exists = EstablishmentSabeelModel::where('establishment_no', $establishment_no)
                ->where('year', $request->year)
                ->exists();

            if ($exists) {
                return $this->error('Sabeel already exists for this establishment and year.', 409);
            }

            EstablishmentSabeelModel::create([
                'establishment_no' => (int) $establishment_no,
                'year'             => (int) $request->year,
                'sabeel'           => (int) $request->sabeel,
                'updated_by'       => (int) Auth::id(),
            ]);

            $payload = $this->buildEstablishmentSummaryPayload($establishment_no);
            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel create failed');
        }
    }

    public function fetch(Request $request, $establishment_no, $id = null)
    {
        try {
            $est = $this->resolveEstablishment($establishment_no);
            if (!$est) {
                return $this->error('Invalid establishment_no. Establishment not found.', 404);
            }

            $payload = $this->buildEstablishmentSummaryPayload($establishment_no);

            // 👇 wrapping in array is IMPORTANT
            return $this->success('Data fetched successfully', [$payload], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel fetch failed');
        }
    }


    public function edit(Request $request, $establishment_no, $id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_no);
            if (!$est) {
                return $this->error('Invalid establishment_no. Establishment not found.', 404);
            }

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_no', $establishment_no)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) return $this->validation($validator);

            $dup = EstablishmentSabeelModel::where('establishment_no', $establishment_no)
                ->where('year', $request->year)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($dup) {
                return $this->error('Another entry already exists for this year.', 409);
            }

            $row->year = (int) $request->year;
            $row->sabeel = (int) $request->sabeel;
            $row->updated_by = (int) Auth::id();
            $row->save();

            $payload = $this->buildEstablishmentSummaryPayload($establishment_no);
            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel update failed');
        }
    }

    // public function delete($establishment_no, $id)
    // {
    //     try {
    //         $est = $this->resolveEstablishment($establishment_no);
    //         if (!$est) {
    //             return $this->error('Invalid establishment_no. Establishment not found.', 404);
    //         }

    //         $row = EstablishmentSabeelModel::where('id', $id)
    //             ->where('establishment_no', $establishment_no)
    //             ->first();

    //         if (!$row) {
    //             return $this->error('Sabeel entry not found.', 404);
    //         }

    //         $row->delete();

    //         return $this->success('Data deleted successfully', [], 200);

    //     } catch (\Throwable $e) {
    //         return $this->serverError($e, 'Establishment sabeel delete failed');
    //     }
    // }

    public function delete(Request $request, $establishment_no)
    {
        try {
            $est = $this->resolveEstablishment($establishment_no);
            if (!$est) {
                return $this->error('Invalid establishment_no. Establishment not found.', 404);
            }

            // ✅ validate year from form-data
            $validator = Validator::make($request->all(), [
                'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $row = EstablishmentSabeelModel::where('establishment_no', $establishment_no)
                ->where('year', (int) $request->year)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found for this year.', 404);
            }

            $row->delete();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel delete failed');
        }
    }


    private function resolveEstablishment($establishment_no): ?EstablishmentModel
    {
        return EstablishmentModel::where('establishment_no', $establishment_no)->first();
    }

    /**
     * Build establishment sabeel summary payload
     * Uses PRIMARY KEY id internally
     */
    private function buildEstablishmentSummaryPayload(int $establishment_no): array
    {
        $rows = EstablishmentSabeelModel::where('establishment_no', $establishment_no)
            ->orderBy('year', 'desc')
            ->get();

        $details = $rows->map(function ($r) use ($establishment_no) {

            $paid = $this->paidForYear($establishment_no, (int)$r->year);
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

    private function paidForYear(int $establishment_no, int $year): int
    {
        return (int) ReceiptModel::where('establishment_no', $establishment_no)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');
    }
}
