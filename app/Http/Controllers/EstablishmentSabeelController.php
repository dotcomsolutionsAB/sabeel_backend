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

class EstablishmentSabeelController extends Controller
{
    //
    use ApiResponse;

    /**
     * CREATE
     * POST /establishment_details/{establishment_id}/create
     * Body: { "year": 2025, "sabeel": 4200 }
     * updated_by from token
     */
    public function create(Request $request, $establishment_id)
    {
        try {
            // validate establishment exists
            $est = EstablishmentModel::find($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // prevent duplicate year for same establishment
            $exists = EstablishmentSabeelModel::where('establishment_id', $establishment_id)
                ->where('year', $request->year)
                ->exists();

            if ($exists) {
                return $this->error('Sabeel already exists for this establishment and year.', 409);
            }

            EstablishmentSabeelModel::create([
                'establishment_id' => (int) $establishment_id,
                'year'             => (int) $request->year,
                'sabeel'           => (int) $request->sabeel,
                'updated_by'       => (int) Auth::id(),
            ]);

            $payload = $this->buildEstablishmentSummaryPayload((int)$establishment_id);

            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel create failed');
        }
    }

    /**
     * FETCH summary
     * POST /establishment_details/{establishment_id}/retrieve/{id?}
     * If id is given -> only validates that entry belongs to establishment
     */
    public function fetch(Request $request, $establishment_id, $id = null)
    {
        try {
            $est = EstablishmentModel::find($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            if ($id !== null) {
                $entry = EstablishmentSabeelModel::where('id', $id)
                    ->where('establishment_id', $establishment_id)
                    ->first();

                if (!$entry) {
                    return $this->error('Sabeel entry not found for this establishment.', 404);
                }
            }

            $payload = $this->buildEstablishmentSummaryPayload((int)$establishment_id);

            return $this->success('Data fetched successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel fetch failed');
        }
    }

    /**
     * UPDATE
     * POST /establishment_details/{establishment_id}/update/{id}
     * Body: { "year": 2025, "sabeel": 5000 }
     */
    public function edit(Request $request, $establishment_id, $id)
    {
        try {
            $est = EstablishmentModel::find($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_id', $establishment_id)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found for this establishment.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // prevent year duplicate if changing year
            $dup = EstablishmentSabeelModel::where('establishment_id', $establishment_id)
                ->where('year', $request->year)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($dup) {
                return $this->error('Another entry already exists for this establishment and year.', 409);
            }

            $row->year = (int) $request->year;
            $row->sabeel = (int) $request->sabeel;
            $row->updated_by = (int) Auth::id();
            $row->save();

            $payload = $this->buildEstablishmentSummaryPayload((int)$establishment_id);

            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel update failed');
        }
    }

    /**
     * DELETE
     * DELETE /establishment_details/{establishment_id}/delete/{id}
     */
    public function delete($establishment_id, $id)
    {
        try {
            $est = EstablishmentModel::find($establishment_id);
            if (!$est) {
                return $this->error('Invalid establishment_id. Establishment not found.', 404);
            }

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_id', $establishment_id)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found for this establishment.', 404);
            }

            $row->delete();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel delete failed');
        }
    }

    /* ----------------- Helpers ----------------- */

    private function resolveYears(): array
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

    private function estDueForYear(int $establishment_id, int $year): array
    {
        $sabeel = (int) EstablishmentSabeelModel::where('establishment_id', $establishment_id)
            ->where('year', $year)
            ->value('sabeel');

        $paid = (float) ReceiptModel::where('establishment_id', $establishment_id)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');

        $due = max(0, $sabeel - $paid);

        return [$sabeel, $due];
    }

    private function buildEstablishmentSummaryPayload(int $establishment_id): array
    {
        $est = EstablishmentModel::find($establishment_id);

        [$currentYear, $prevYear] = $this->resolveYears();

        [$curSabeel, $curDue]   = $this->estDueForYear($establishment_id, $currentYear);
        [$prevSabeel, $prevDue] = $this->estDueForYear($establishment_id, $prevYear);

        return [
            'id'               => (string) ($est->id ?? ''),
            'establishment_id' => (string) ($est->establishment_no ?? ''),
            'name'             => (string) ($est->name ?? ''),
            'address'          => (string) ($est->address ?? ''),

            'establishment' => [
                'sabeel'   => (string) $curSabeel,
                'due'      => (string) $curDue,
                'prev_due' => (string) $prevDue,
            ],
        ];
    }
}
