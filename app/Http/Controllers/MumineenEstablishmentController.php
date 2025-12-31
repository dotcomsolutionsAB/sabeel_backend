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
     * POST /partners/create/{establishment_no}
     * Body: { "its": "12345678" }
     */
    public function create(Request $request, $establishment_no)
    {
        try {
            $validator = Validator::make($request->all(), [
                'its' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // ✅ validate establishment using establishment_no (not id)
            $est = EstablishmentModel::where('establishment_no', $establishment_no)->first();
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
                'establishment_no' => $establishment_no,
            ])->exists();

            if ($exists) {
                return $this->error('Already linked with this establishment.', 422);
            }

            $row = MumineenEstablishmentModel::create([
                'family_id'        => $mumineen->family_id,
                'its'              => $mumineen->its,
                'establishment_no' => (int) $establishment_no,
                'updated_by'       => (int) Auth::id(),
            ]);

            return $this->success('Partner linked successfully.', $row, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Partner create failed');
        }
    }

    /**
     * FETCH
     * POST /partners/retrieve/{establishment_no}/{id?}
     */
    public function fetch(Request $request, $establishment_no, $id = null)
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
            $rows = MumineenEstablishmentModel::with('mumineen')
                ->where('establishment_no', (int)$establishment_no)
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
     */
    public function delete($id)
    {
        try {
            $row = MumineenEstablishmentModel::find($id);
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
        return [
            'id'     => (string) $r->id,
            'url'    => '', // frontend can build if needed
            'name'   => (string) ($r->mumineen->name ?? ''),
            'its'    => (string) $r->its,
            'mobile' => (string) ($r->mumineen->mobile ?? ''),
        ];
    }
}
