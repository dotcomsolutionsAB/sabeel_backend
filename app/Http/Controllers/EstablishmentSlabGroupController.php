<?php

namespace App\Http\Controllers;

use App\Models\EstablishmentModel;
use App\Models\EstablishmentSlabGroupMemberModel;
use App\Models\EstablishmentSlabGroupModel;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EstablishmentSlabGroupController extends Controller
{
    use ApiResponse;

    /**
     * POST /establishment/slab-group/list
     */
    public function list(Request $request)
    {
        try {
            $onlyActive = $request->boolean('only_active', true);
            $q = EstablishmentSlabGroupModel::query()->with('members');
            if ($onlyActive) {
                $q->where('is_active', true);
            }
            $groups = $q->orderBy('primary_establishment_id')->get();

            $data = $groups->map(function (EstablishmentSlabGroupModel $g) {
                $primaryName = EstablishmentModel::query()
                    ->where('establishment_id', $g->primary_establishment_id)
                    ->value('name');

                return [
                    'id'                       => $g->id,
                    'primary_establishment_id' => $g->primary_establishment_id,
                    'primary_name'             => $primaryName,
                    'label'                    => $g->label,
                    'remarks'                  => $g->remarks,
                    'is_active'                => $g->is_active,
                    'member_establishment_ids' => $g->members->pluck('establishment_id')->values()->all(),
                ];
            });

            return $this->success('Slab merge groups', ['groups' => $data], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Slab group list failed');
        }
    }

    /**
     * POST /establishment/slab-group/create
     */
    public function create(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'primary_establishment_id' => 'required|string|max:32',
                'member_establishment_ids' => 'required|array|min:1',
                'member_establishment_ids.*' => 'required|string|max:32',
                'label'                    => 'nullable|string|max:255',
                'remarks'                  => 'nullable|string',
            ]);
            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $primary = trim((string) $request->input('primary_establishment_id'));
            $members = array_values(array_unique(array_map('strval', $request->input('member_establishment_ids', []))));
            if (!in_array($primary, $members, true)) {
                $members[] = $primary;
            }

            $err = $this->validateMemberEstablishments($primary, $members);
            if ($err !== null) {
                return $this->error($err, 422);
            }

            return DB::transaction(function () use ($request, $primary, $members) {
                $group = EstablishmentSlabGroupModel::create([
                    'primary_establishment_id' => $primary,
                    'label'                    => $request->input('label'),
                    'remarks'                  => $request->input('remarks'),
                    'is_active'                => true,
                ]);
                foreach ($members as $eid) {
                    EstablishmentSlabGroupMemberModel::create([
                        'group_id'          => $group->id,
                        'establishment_id'  => $eid,
                    ]);
                }

                return $this->success('Slab merge group created', [
                    'id' => $group->id,
                ], 201);
            });
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Slab group create failed');
        }
    }

    /**
     * POST /establishment/slab-group/update/{id}
     */
    public function update(Request $request, int $id)
    {
        try {
            $group = EstablishmentSlabGroupModel::query()->find($id);
            if (!$group) {
                return $this->error('Group not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'primary_establishment_id' => 'required|string|max:32',
                'member_establishment_ids' => 'required|array|min:1',
                'member_establishment_ids.*' => 'required|string|max:32',
                'label'                    => 'nullable|string|max:255',
                'remarks'                  => 'nullable|string',
                'is_active'                => 'nullable|boolean',
            ]);
            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $primary = trim((string) $request->input('primary_establishment_id'));
            $members = array_values(array_unique(array_map('strval', $request->input('member_establishment_ids', []))));
            if (!in_array($primary, $members, true)) {
                $members[] = $primary;
            }

            $err = $this->validateMemberEstablishments($primary, $members, $group->id);
            if ($err !== null) {
                return $this->error($err, 422);
            }

            return DB::transaction(function () use ($request, $group, $primary, $members) {
                $group->update([
                    'primary_establishment_id' => $primary,
                    'label'                    => $request->input('label'),
                    'remarks'                  => $request->input('remarks'),
                    'is_active'                => $request->exists('is_active') ? (bool) $request->input('is_active') : $group->is_active,
                ]);
                EstablishmentSlabGroupMemberModel::query()->where('group_id', $group->id)->delete();
                foreach ($members as $eid) {
                    EstablishmentSlabGroupMemberModel::create([
                        'group_id'         => $group->id,
                        'establishment_id' => $eid,
                    ]);
                }

                return $this->success('Slab merge group updated', ['id' => $group->id], 200);
            });
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Slab group update failed');
        }
    }

    /**
     * POST /establishment/slab-group/delete/{id}
     * Soft-deactivate (is_active = false). Hard delete optional via force=1.
     */
    public function delete(Request $request, int $id)
    {
        try {
            $group = EstablishmentSlabGroupModel::query()->find($id);
            if (!$group) {
                return $this->error('Group not found.', 404);
            }

            if ($request->boolean('force')) {
                $group->delete();

                return $this->success('Slab merge group deleted', [], 200);
            }

            $group->update(['is_active' => false]);

            return $this->success('Slab merge group deactivated', ['id' => $group->id], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Slab group delete failed');
        }
    }

    /**
     * @param array<int, string> $members
     */
    private function validateMemberEstablishments(string $primary, array $members, ?int $excludeGroupId = null): ?string
    {
        if (!EstablishmentModel::query()->where('establishment_id', $primary)->exists()) {
            return 'Primary establishment not found.';
        }

        foreach ($members as $eid) {
            if (!EstablishmentModel::query()->where('establishment_id', $eid)->exists()) {
                return "Establishment not found: {$eid}";
            }
        }

        foreach ($members as $eid) {
            $q = EstablishmentSlabGroupMemberModel::query()->where('establishment_id', $eid);
            if ($excludeGroupId !== null) {
                $q->where('group_id', '!=', $excludeGroupId);
            }
            if ($q->exists()) {
                return "Establishment {$eid} is already in another slab merge group.";
            }
        }

        return null;
    }
}
