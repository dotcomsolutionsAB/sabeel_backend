<?php

namespace App\Http\Controllers;

use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenSabeelModel;
use App\Models\YearModel;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class YearController extends Controller
{
    use ApiResponse;

    /**
     * Copy mumineen and establishment sabeel from one year to another (same amounts).
     * Ensures t_year has a row for the new year.
     *
     * POST /year/copy-sabeel-to-year
     * Body: {
     *   "new_year": "2026-27",
     *   "from_year": "2025-26",
     *   "make_current": false
     * }
     */
    public function copySabeelToNewYear(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'new_year'     => 'required|string|max:10',
                'from_year'    => 'required|string|max:10',
                'make_current' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $newYear = trim((string) $request->input('new_year'));
            $fromYear = trim((string) $request->input('from_year'));
            $makeCurrent = (bool) $request->boolean('make_current', false);

            if ($newYear === $fromYear) {
                return $this->error('new_year and from_year must be different.', 422);
            }

            $mumineenSourceCount = MumineenSabeelModel::where('year', $fromYear)->count();
            $establishmentSourceCount = EstablishmentSabeelModel::where('year', $fromYear)->count();

            if ($mumineenSourceCount === 0 && $establishmentSourceCount === 0) {
                return $this->error('No sabeel entries found for the source year.', 422);
            }

            $updatedBy = (int) (Auth::id() ?? 0);

            $stats = [
                'mumineen_created'       => 0,
                'mumineen_skipped'       => 0,
                'establishment_created'  => 0,
                'establishment_skipped'  => 0,
                'year_row_created'       => false,
            ];

            DB::transaction(function () use ($newYear, $fromYear, $makeCurrent, $updatedBy, &$stats) {
                $yearRow = YearModel::firstOrCreate(
                    ['year' => $newYear],
                    ['is_current' => false]
                );
                $stats['year_row_created'] = $yearRow->wasRecentlyCreated;

                if ($makeCurrent) {
                    YearModel::query()->update(['is_current' => false]);
                    YearModel::where('year', $newYear)->update(['is_current' => true]);
                }

                $mumineenRows = MumineenSabeelModel::where('year', $fromYear)->get();
                foreach ($mumineenRows as $row) {
                    $exists = MumineenSabeelModel::where('family_id', $row->family_id)
                        ->where('year', $newYear)
                        ->exists();
                    if ($exists) {
                        $stats['mumineen_skipped']++;
                        continue;
                    }
                    MumineenSabeelModel::create([
                        'family_id'  => $row->family_id,
                        'year'       => $newYear,
                        'sabeel'     => $row->sabeel,
                        'updated_by' => $updatedBy,
                    ]);
                    $stats['mumineen_created']++;
                }

                $estRows = EstablishmentSabeelModel::where('year', $fromYear)->get();
                foreach ($estRows as $row) {
                    $exists = EstablishmentSabeelModel::where('establishment_id', $row->establishment_id)
                        ->where('year', $newYear)
                        ->exists();
                    if ($exists) {
                        $stats['establishment_skipped']++;
                        continue;
                    }
                    EstablishmentSabeelModel::create([
                        'establishment_id' => $row->establishment_id,
                        'year'             => $newYear,
                        'sabeel'           => $row->sabeel,
                        'updated_by'       => $updatedBy,
                    ]);
                    $stats['establishment_created']++;
                }
            });

            return $this->success('Sabeel copied to new year.', [
                'new_year'  => $newYear,
                'from_year' => $fromYear,
                'make_current' => $makeCurrent,
                ...$stats,
            ], 200);
        } catch (\Throwable $e) {
            return $this->serverError($e, 'Copy sabeel to new year failed');
        }
    }
}
