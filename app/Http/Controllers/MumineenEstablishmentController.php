<?php

namespace App\Http\Controllers;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

use App\Models\MumineenEstablishmentModel;
use App\Models\MumineenModel;
use App\Models\EstablishmentModel;

class MumineenEstablishmentController extends Controller
{
    //
    use ApiResponse;

    /**
     * CREATE
     * POST /partners/create/{establishment_id}
     * Body: { "its": "12345678" }
     */
    public function create(Request $request, $establishment_id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'its' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // ✅ validate establishment using establishment_id (not id)
            $est = EstablishmentModel::where('establishment_id', $establishment_id)->first();
            if (!$est) {
                return $this->error('Invalid establishment.', 404);
            }

            // find mumineen by ITS
            $mumineen = MumineenModel::where('its', $request->its)->first();
            if (!$mumineen) {
                return $this->error('Invalid ITS. Mumineen not found.', 404);
            }

            // prevent duplicate mapping
            $exists = MumineenEstablishmentModel::where([
                'its'              => $mumineen->its,
                'establishment_id' => $establishment_id,
            ])->exists();

            if ($exists) {
                return $this->error('Already linked with this establishment.', 422);
            }

            $row = MumineenEstablishmentModel::create([
                'family_id'        => $mumineen->family_id,
                'its'              => $mumineen->its,
                'establishment_id' => (int) $establishment_id,
                'updated_by'       => (int) Auth::id(),
            ]);

            return $this->success('Partner linked successfully.', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Partner create failed');
        }
    }

    /**
     * FETCH
     * POST /partners/retrieve/{establishment_id}/{id?}
     */
    public function fetch(Request $request, $establishment_id, $id = null)
    {
        try {
            // SINGLE
            if ($id !== null) {
                $row = MumineenEstablishmentModel::find($id);
                if (!$row) {
                    return $this->error('Record not found.', 404);
                }

                return $this->success('Data fetched successfully', [
                    $this->mapRow($row)
                ], 200);
            }

            // LIST
            $rows = MumineenEstablishmentModel::where('establishment_id', (int)$establishment_id)
                ->orderBy('id', 'desc')
                ->get();

            $data = $rows->map(fn ($r) => $this->mapRow($r))->values();

            return $this->success('Data fetched successfully', $data, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Partner fetch failed');
        }
    }

    /**
     * UPDATE
     * POST /partners/update/{id}
     * Body: { "its": "12345678" }
     */
    public function edit(Request $request, $id)
    {
        try {
            $row = MumineenEstablishmentModel::find($id);
            if (!$row) {
                return $this->error('Record not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'its' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $mumineen = MumineenModel::where('its', $request->its)->first();
            if (!$mumineen) {
                return $this->error('Invalid ITS. Mumineen not found.', 404);
            }

            $row->family_id  = $mumineen->family_id;
            $row->its        = $mumineen->its;
            $row->updated_by = (int) Auth::id();
            $row->save();

            return $this->success('Data updated successfully', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Partner update failed');
        }
    }

    /**
     * DELETE
     * DELETE /partners/delete/{id}
     * Optional query: establishment_id — used to resolve link by ITS if id is not a link row id
     */
    public function delete(Request $request, $id)
    {
        try {
            $row = MumineenEstablishmentModel::find($id);

            if (!$row && $request->filled('establishment_id')) {
                $row = MumineenEstablishmentModel::where('establishment_id', (int) $request->query('establishment_id'))
                    ->where('its', (string) $id)
                    ->first();
            }

            if (!$row) {
                return $this->error('Record not found.', 404);
            }

            $row->delete();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Partner delete failed');
        }
    }

    /* ---------------- Helpers ---------------- */

    private function mapRow(MumineenEstablishmentModel $r): array
    {
        // Fetch partner by family_id (HOF)
        $partner = MumineenModel::where('family_id', $r->family_id)
            ->where('hof_type', 'HOF')
            ->where('status', 'active')
            ->first();

        return [
            'id'     => (string) $r->id,
            'url'    => $partner ? $partner->pic : '',
            'name'   => (string) ($partner->name ?? ''),
            'its'    => (string) ($partner->its ?? ''),
            'mobile' => (string) ($partner->mobile ?? ''),
        ];
    }
}
