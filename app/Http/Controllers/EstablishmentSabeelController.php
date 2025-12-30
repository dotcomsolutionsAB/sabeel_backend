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

            // SINGLE
            if ($id !== null) {
                $entry = EstablishmentSabeelModel::where('id', $id)
                    ->where('establishment_no', $establishment_no)
                    ->first();

                if (!$entry) {
                    return $this->error('Sabeel entry not found.', 404);
                }

                return $this->success(
                    'Data fetched successfully',
                    $this->buildEstablishmentSummaryPayload($establishment_no),
                    200
                );
            }

            // LIST
            $payload = $this->buildEstablishmentSummaryPayload($establishment_no);
            return $this->success('Data fetched successfully', $payload, 200);

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

    public function delete($establishment_no, $id)
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
    private function buildEstablishmentSummaryPayload($establishment_no): array
    {
        $est = EstablishmentModel::where('establishment_no', $establishment_no)->first();
        if (!$est) return [];

        $rows = EstablishmentSabeelModel::where('establishment_no', $establishment_no)
            ->orderBy('year', 'desc')
            ->get();

        return [
            'establishment' => [
                'establishment_no' => (string) $est->establishment_no,
                'name'             => (string) $est->name,
            ],
            'sabeel' => $rows->map(fn ($r) => [
                'id'     => (string) $r->id,
                'year'   => (string) $r->year,
                'sabeel' => (string) $r->sabeel,
            ])->values(),
        ];
    }
}
