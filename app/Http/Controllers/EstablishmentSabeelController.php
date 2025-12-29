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
            if (!$est) return $this->error('Invalid establishment_id. Establishment not found.', 404);

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) return $this->validation($validator);

            $exists = EstablishmentSabeelModel::where('establishment_id', $est->id)
                ->where('year', $request->year)
                ->exists();

            if ($exists) return $this->error('Sabeel already exists for this establishment and year.', 409);

            EstablishmentSabeelModel::create([
                'establishment_id' => (int) $est->id,   // ✅ store PK id
                'year'             => (int) $request->year,
                'sabeel'           => (int) $request->sabeel,
                'updated_by'       => (int) Auth::id(),
            ]);

            $payload = $this->buildEstablishmentSummaryPayload((int)$est->id);
            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel create failed');
        }
    }

    public function fetch(Request $request, $establishment_id, $id = null)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) return $this->error('Invalid establishment_id. Establishment not found.', 404);

            if ($id !== null) {
                $entry = EstablishmentSabeelModel::where('id', $id)
                    ->where('establishment_id', $est->id)
                    ->first();

                if (!$entry) return $this->error('Sabeel entry not found for this establishment.', 404);
            }

            $payload = $this->buildEstablishmentSummaryPayload((int)$est->id);
            return $this->success('Data fetched successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel fetch failed');
        }
    }

    public function edit(Request $request, $establishment_id, $id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) return $this->error('Invalid establishment_id. Establishment not found.', 404);

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_id', $est->id)
                ->first();

            if (!$row) return $this->error('Sabeel entry not found for this establishment.', 404);

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);
            if ($validator->fails()) return $this->validation($validator);

            $dup = EstablishmentSabeelModel::where('establishment_id', $est->id)
                ->where('year', $request->year)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($dup) return $this->error('Another entry already exists for this establishment and year.', 409);

            $row->year = (int) $request->year;
            $row->sabeel = (int) $request->sabeel;
            $row->updated_by = (int) Auth::id();
            $row->save();

            $payload = $this->buildEstablishmentSummaryPayload((int)$est->id);
            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel update failed');
        }
    }

    public function delete($establishment_id, $id)
    {
        try {
            $est = $this->resolveEstablishment($establishment_id);
            if (!$est) return $this->error('Invalid establishment_id. Establishment not found.', 404);

            $row = EstablishmentSabeelModel::where('id', $id)
                ->where('establishment_id', $est->id)
                ->first();

            if (!$row) return $this->error('Sabeel entry not found for this establishment.', 404);

            $row->delete();
            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sabeel delete failed');
        }
    }

    private function resolveEstablishment($establishment_id): ?EstablishmentModel
    {
        return EstablishmentModel::where('establishment_no', $establishment_id)->first();
        // or support both:
        // return EstablishmentModel::where('id',$establishment_id)->orWhere('establishment_no',$establishment_id)->first();
    }

    // resolveYears(), estDueForYear(), buildEstablishmentSummaryPayload() remain SAME
    // because they already accept the PRIMARY KEY id now (we pass $est->id)
}
