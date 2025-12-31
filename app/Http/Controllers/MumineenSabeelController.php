<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\ReceiptModel;
use App\Models\YearModel;

class MumineenSabeelController extends Controller
{
    //
        use ApiResponse;

    /**
     * CREATE: /family_details/{family_id}/create
     * Body: { "year": 2025, "sabeel": 2100 }
     * updated_by from token
     */
    public function create(Request $request, $family_id)
    {
        try {
            // validate family exists
            $hof = $this->getHofByFamilyId($family_id);
            if (!$hof) {
                return $this->error('Invalid family_id. Family not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // optional: prevent duplicate year for same family
            $exists = MumineenSabeelModel::where('family_id', $family_id)
                ->where('year', $request->year)
                ->exists();

            if ($exists) {
                return $this->error('Sabeel already exists for this family and year.', 409);
            }

            $row = MumineenSabeelModel::create([
                'family_id'  => (int) $family_id,
                'year'       => (int) $request->year,
                'sabeel'     => (int) $request->sabeel,
                'updated_by' => (int) Auth::id(),
            ]);

            // return computed summary payload (same as fetch response)
            $payload = $this->buildFamilySummaryPayload((int)$family_id);

            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen sabeel create failed');
        }
    }

    /**
     * FETCH: /family_details/{family_id}/retrieve/{id?}
     * returns your summary JSON structure
     */
    public function fetch(Request $request, $family_id, $id = null)
    {
        try {
            $hof = $this->getHofByFamilyId($family_id);
            if (!$hof) return $this->error('Invalid family_id. Family not found.', 404);

            if ($id !== null) {
                $entry = MumineenSabeelModel::where('id', $id)
                    ->where('family_id', $family_id)
                    ->first();

                if (!$entry) return $this->error('Sabeel entry not found for this family.', 404);
            }

            // build details only
            [, , $yearsList] = $this->resolveYears();
            $sabeelDetails = $this->buildFamilySabeelDetails((int)$family_id, $yearsList);

            $payload = [
                [
                    'sabeel_details' => $sabeelDetails
                ]
            ];

            return $this->success('Data fetched successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen sabeel fetch failed');
        }
    }

    /**
     * UPDATE: /family_details/{family_id}/update/{id}
     * Body: { "year": 2025, "sabeel": 2200 }
     */
    public function edit(Request $request, $family_id, $id)
    {
        try {
            // validate family exists
            $hof = $this->getHofByFamilyId($family_id);
            if (!$hof) {
                return $this->error('Invalid family_id. Family not found.', 404);
            }

            $row = MumineenSabeelModel::where('id', $id)
                ->where('family_id', $family_id)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found for this family.', 404);
            }

            $validator = Validator::make($request->all(), [
                'year'   => 'required|integer|min:2000|max:2100',
                'sabeel' => 'required|integer|min:0',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            // prevent year duplicate if changing year
            $dup = MumineenSabeelModel::where('family_id', $family_id)
                ->where('year', $request->year)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($dup) {
                return $this->error('Another entry already exists for this family and year.', 409);
            }

            $row->year = (int) $request->year;
            $row->sabeel = (int) $request->sabeel;
            $row->updated_by = (int) Auth::id();
            $row->save();

            $payload = $this->buildFamilySummaryPayload((int)$family_id);

            return $this->success('Data saved successfully', $payload, 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen sabeel update failed');
        }
    }

    /**
     * DELETE: /family_details/{family_id}/delete/{id}
     */
    public function delete($family_id, $id)
    {
        try {
            // validate family exists
            $hof = $this->getHofByFamilyId($family_id);
            if (!$hof) {
                return $this->error('Invalid family_id. Family not found.', 404);
            }

            $row = MumineenSabeelModel::where('id', $id)
                ->where('family_id', $family_id)
                ->first();

            if (!$row) {
                return $this->error('Sabeel entry not found for this family.', 404);
            }

            $row->delete();

            return $this->success('Data deleted successfully', [], 200);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen sabeel delete failed');
        }
    }

    /* ----------------- Helpers ----------------- */

    private function getHofByFamilyId($family_id)
    {
        // If your DB enforces unique family_id, this returns one row.
        // If not, prefer HOF.
        $hof = MumineenModel::where('family_id', $family_id)
            ->where('hof_type', 'HOF')
            ->first();

        if (!$hof) {
            $hof = MumineenModel::where('family_id', $family_id)->first();
        }

        return $hof;
    }

    private function resolveYears(): array
    {
        // returns: [$currentYear, $prevYear, $yearsList]
        if (!Schema::hasTable('t_year')) {
            $cur = (int) date('Y');
            return [$cur, $cur - 1, [$cur, $cur - 1, $cur - 2]];
        }

        $currentYear = (int) YearModel::where('is_current', 1)->value('year');
        if (!$currentYear) $currentYear = (int) YearModel::max('year');
        if (!$currentYear) $currentYear = (int) date('Y');

        $yearsList = YearModel::orderBy('year', 'desc')->pluck('year')->take(3)->toArray();
        if (count($yearsList) < 3) {
            $yearsList = [$currentYear, $currentYear - 1, $currentYear - 2];
        }

        $prevYear = collect($yearsList)->filter(fn($y) => $y < $currentYear)->first();
        $prevYear = $prevYear ? (int)$prevYear : ($currentYear - 1);

        return [$currentYear, $prevYear, $yearsList];
    }

    private function yearLabel(int $year): string
    {
        $next = substr((string)($year + 1), -2);
        return "{$year}-{$next}";
    }

    private function familyDueForYear(int $family_id, int $year): array
    {
        $sabeel = (int) MumineenSabeelModel::where('family_id', $family_id)
            ->where('year', $year)
            ->value('sabeel');

        $paid = (float) ReceiptModel::where('family_id', $family_id)
            ->where('year', $year)
            ->where('status', 'active')
            ->sum('amount');

        $due = max(0, $sabeel - $paid);

        return [$sabeel, $due];
    }

    private function establishmentSummaryForFamily(int $family_id, int $currentYear, int $prevYear): array
    {
        $estIds = MumineenEstablishmentModel::where('family_id', $family_id)
            ->pluck('establishment_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($estIds)) {
            return [0, 0, 0]; // sabeel, due, prev_due
        }

        $curSabeelSum = 0; $curDueSum = 0; $prevDueSum = 0;

        foreach ($estIds as $estId) {
            $curSabeel = (int) EstablishmentSabeelModel::where('establishment_id', $estId)
                ->where('year', $currentYear)
                ->value('sabeel');

            $curPaid = (float) ReceiptModel::where('establishment_id', $estId)
                ->where('year', $currentYear)
                ->where('status', 'active')
                ->sum('amount');

            $curDue = max(0, $curSabeel - $curPaid);

            $prevSabeel = (int) EstablishmentSabeelModel::where('establishment_id', $estId)
                ->where('year', $prevYear)
                ->value('sabeel');

            $prevPaid = (float) ReceiptModel::where('establishment_id', $estId)
                ->where('year', $prevYear)
                ->where('status', 'active')
                ->sum('amount');

            $prevDue = max(0, $prevSabeel - $prevPaid);

            $curSabeelSum += $curSabeel;
            $curDueSum += $curDue;
            $prevDueSum += $prevDue;
        }

        return [$curSabeelSum, $curDueSum, $prevDueSum];
    }

    private function buildFamilySummaryPayload(int $family_id): array
    {
        $hof = $this->getHofByFamilyId($family_id);

        [$currentYear, $prevYear, $yearsList] = $this->resolveYears();

        [$curSabeel, $curDue] = $this->familyDueForYear($family_id, $currentYear);
        [$prevSabeel, $prevDue] = $this->familyDueForYear($family_id, $prevYear);

        [$estSabeel, $estDue, $estPrevDue] = $this->establishmentSummaryForFamily($family_id, $currentYear, $prevYear);

        $sabeelDetails = $this->buildFamilySabeelDetails($family_id, $yearsList);

        return [
            'id'        => (string) ($hof->id ?? ''),
            'family_id' => (string) $family_id,
            'url'       => "https://talabulilm.com/mumin_images/{$hof->its}.png",

            'name'      => (string) ($hof->name ?? ''),
            'its'       => (string) ($hof->its ?? ''),
            'sector'    => (string) ($hof->sector ?? ''),
            'mobile'    => (string) ($hof->mobile ?? ''),
            'email'     => (string) ($hof->email ?? ''),

            'sabeel' => [
                'sabeel'   => (string) $curSabeel,
                'due'      => (string) $curDue,
                'prev_due' => (string) $prevDue,
            ],

            // ✅ NEW: year wise list
            'sabeel_details' => $sabeelDetails,

            'establishment' => [
                'sabeel'   => (string) $estSabeel,
                'due'      => (string) $estDue,
                'prev_due' => (string) $estPrevDue,
            ],
        ];
    }

    private function buildFamilySabeelDetails(int $family_id, array $yearsList): array
    {
        $details = [];

        foreach ($yearsList as $yr) {
            [$sabeel, $due] = $this->familyDueForYear($family_id, (int)$yr);

            $details[] = [
                'year'   => $this->yearLabel((int)$yr),
                'sabeel' => (string) $sabeel,
                'due'    => (string) $due,
            ];
        }

        return $details;
    }
}
