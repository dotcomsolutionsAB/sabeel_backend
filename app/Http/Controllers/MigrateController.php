<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Models\YearModel;
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\EstablishmentModel;
use App\Models\EstablishmentSabeelModel;
use App\Models\MumineenEstablishmentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class MigrateController extends Controller
{
    use ApiResponse;

    /**
     * Sync years from external API
     * URL: https://sabeel.kolkatajamaat.com/assets/custom/migrate/year.php
     */
    public function syncYear(Request $request)
    {
        try {
            $url = 'https://sabeel.kolkatajamaat.com/assets/custom/migrate/year.php';
            
            // Fetch data from external API
            $response = Http::timeout(30)->get($url);
            
            if (!$response->successful()) {
                return $this->error('Failed to fetch data from external API', $response->status());
            }

            $data = $response->json();
            
            if (!isset($data['data']) || !is_array($data['data'])) {
                return $this->error('Invalid response format from API', 422);
            }

            $syncedCount = 0;
            $currentYearId = null;

            // Use transaction to ensure data consistency
            DB::beginTransaction();

            try {
                foreach ($data['data'] as $item) {
                    // Store year as-is (format: "2017-18")
                    $yearString = $item['year'] ?? '';
                    
                    if (empty($yearString)) {
                        continue; // Skip empty years
                    }

                    // Convert current "0"/"1" to boolean
                    $isCurrent = ($item['current'] ?? '0') === '1';

                    // Update or create year record
                    $year = YearModel::updateOrCreate(
                        ['year' => $yearString],
                        ['is_current' => $isCurrent]
                    );

                    if ($isCurrent) {
                        $currentYearId = $year->id;
                    }

                    $syncedCount++;
                }

                // Ensure only one year is marked as current
                if ($currentYearId !== null) {
                    YearModel::where('id', '!=', $currentYearId)
                        ->update(['is_current' => false]);
                }

                DB::commit();

                return $this->success('Years synced successfully', [
                    'synced_count' => $syncedCount,
                    'total_records' => count($data['data'])
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Year sync failed');
        }
    }

    /**
     * Sync mumineen from external API
     * URL: https://sabeel.kolkatajamaat.com/assets/custom/migrate/mumineen.php
     */
    public function syncMumineen(Request $request)
    {
        try {
            $url = 'https://sabeel.kolkatajamaat.com/assets/custom/migrate/mumineen.php';
            
            // Fetch data from external API
            $response = Http::timeout(60)->get($url);
            
            if (!$response->successful()) {
                return $this->error('Failed to fetch data from external API', $response->status());
            }

            $data = $response->json();
            
            if (!isset($data['data']) || !is_array($data['data'])) {
                return $this->error('Invalid response format from API', 422);
            }

            $mumineenSynced = 0;
            $sabeelSynced = 0;
            $processedFamilyIds = [];

            // Use transaction to ensure data consistency
            DB::beginTransaction();

            try {
                foreach ($data['data'] as $item) {
                    $familyId = (int) ($item['family_id'] ?? 0);
                    
                    if ($familyId <= 0) {
                        continue; // Skip invalid family_id
                    }

                    // Process mumineen record (only once per family_id)
                    if (!isset($processedFamilyIds[$familyId])) {
                        // Map hof_fm_type to hof_type
                        $hofType = strtoupper($item['hof_fm_type'] ?? 'HOF');
                        if (!in_array($hofType, ['HOF', 'FM'])) {
                            $hofType = 'HOF';
                        }

                        // Map gender
                        $gender = strtolower($item['gender'] ?? 'male');
                        if (!in_array($gender, ['male', 'female'])) {
                            $gender = 'male';
                        }

                        // Map status: "0" = active, "1" = closed
                        $status = ($item['status'] ?? '0') === '1' ? 'closed' : 'active';

                        // Process mobile: Extract rightmost 10 digits
                        $mobile = $item['mobile'] ?? '';
                        if (!empty($mobile)) {
                            $mobile = substr($mobile, -10);
                        } else {
                            $mobile = null;
                        }

                        // Process age
                        $age = !empty($item['age']) && $item['age'] !== '0' ? (int) $item['age'] : null;

                        // Set placeholder pic URL
                        $picUrl = url('storage/uploads/its_images/placeholder.jpg');

                        // Update or create mumineen record
                        MumineenModel::updateOrCreate(
                            ['family_id' => $familyId],
                            [
                                'hof_type'   => $hofType,
                                'its'        => $item['its'] ?? '',
                                'hof_its'    => !empty($item['hof_its']) ? $item['hof_its'] : null,
                                'family_its' => !empty($item['family_its']) ? $item['family_its'] : null,
                                'name'       => $item['name'] ?? '',
                                'sector'     => !empty($item['sector']) ? $item['sector'] : null,
                                'sub_sector' => null, // Not in API data
                                'mobile'     => $mobile,
                                'email'      => !empty($item['email']) ? $item['email'] : null,
                                'gender'     => $gender,
                                'age'        => $age,
                                'pic'        => $picUrl,
                                'status'     => $status,
                            ]
                        );

                        $processedFamilyIds[$familyId] = true;
                        $mumineenSynced++;
                    }

                    // Process sabeel entry (for each year)
                    $year = $item['year'] ?? '';
                    $sabeel = !empty($item['sabeel']) ? (int) $item['sabeel'] : 0;

                    if (!empty($year) && $sabeel >= 0) {
                        // Use system user ID 1 as default updated_by, or get from auth if available
                        $updatedBy = auth()->check() ? auth()->id() : 1;

                        MumineenSabeelModel::updateOrCreate(
                            [
                                'family_id' => $familyId,
                                'year'      => $year,
                            ],
                            [
                                'sabeel'     => $sabeel,
                                'updated_by' => $updatedBy,
                            ]
                        );

                        $sabeelSynced++;
                    }
                }

                DB::commit();

                return $this->success('Mumineen synced successfully', [
                    'mumineen_synced' => $mumineenSynced,
                    'sabeel_synced'   => $sabeelSynced,
                    'total_records'   => count($data['data'])
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Mumineen sync failed');
        }
    }

    /**
     * Sync establishments from external API
     * URL: https://sabeel.kolkatajamaat.com/assets/custom/migrate/establishment.php
     */
    public function syncEstablishment(Request $request)
    {
        try {
            $url = 'https://sabeel.kolkatajamaat.com/assets/custom/migrate/establishment.php';
            
            // Fetch data from external API
            $response = Http::timeout(60)->get($url);
            
            if (!$response->successful()) {
                return $this->error('Failed to fetch data from external API', $response->status());
            }

            $data = $response->json();
            
            if (!isset($data['data']) || !is_array($data['data'])) {
                return $this->error('Invalid response format from API', 422);
            }

            $establishmentSynced = 0;
            $sabeelSynced = 0;
            $ownerMappingsSynced = 0;
            $processedEstablishmentIds = [];

            // Use transaction to ensure data consistency
            DB::beginTransaction();

            try {
                foreach ($data['data'] as $item) {
                    $establishmentId = (int) ($item['establishment_no'] ?? 0);
                    
                    if ($establishmentId <= 0) {
                        continue; // Skip invalid establishment_id
                    }

                    // Process establishment record (only once per establishment_id)
                    if (!isset($processedEstablishmentIds[$establishmentId])) {
                        // Map type: "0" = business, "1" = manufacturer
                        $type = ($item['type'] ?? '0') === '1' ? 'manufacturer' : 'business';

                        // Map status: "0" = active, "1" = closed
                        $status = ($item['status'] ?? '0') === '1' ? 'closed' : 'active';

                        // Update or create establishment record
                        EstablishmentModel::updateOrCreate(
                            ['establishment_id' => $establishmentId],
                            [
                                'name'    => $item['name'] ?? '',
                                'address' => $item['address'] ?? '',
                                'status'  => $status,
                                'type'    => $type,
                                'remarks' => null, // Not in API data
                            ]
                        );

                        // Process owner mappings (only once per establishment)
                        $owners = $item['owners'] ?? [];
                        if (is_array($owners) && !empty($owners)) {
                            $updatedBy = auth()->check() ? auth()->id() : 1;

                            foreach ($owners as $ownerIts) {
                                if (empty($ownerIts)) {
                                    continue; // Skip empty ITS
                                }

                                // Find mumineen by ITS
                                $mumineen = MumineenModel::where('its', (string) $ownerIts)->first();
                                
                                if ($mumineen) {
                                    // Create or update mapping (avoid duplicates)
                                    MumineenEstablishmentModel::updateOrCreate(
                                        [
                                            'establishment_id' => $establishmentId,
                                            'its'              => $mumineen->its,
                                        ],
                                        [
                                            'family_id'  => $mumineen->family_id,
                                            'updated_by' => $updatedBy,
                                        ]
                                    );
                                    $ownerMappingsSynced++;
                                }
                            }
                        }

                        $processedEstablishmentIds[$establishmentId] = true;
                        $establishmentSynced++;
                    }

                    // Process sabeel entry (for each year)
                    $year = $item['year'] ?? '';
                    $sabeel = !empty($item['sabeel']) ? (int) $item['sabeel'] : 0;

                    if (!empty($year) && $sabeel >= 0) {
                        $updatedBy = auth()->check() ? auth()->id() : 1;

                        EstablishmentSabeelModel::updateOrCreate(
                            [
                                'establishment_id' => $establishmentId,
                                'year'             => $year,
                            ],
                            [
                                'sabeel'     => $sabeel,
                                'updated_by' => $updatedBy,
                            ]
                        );

                        $sabeelSynced++;
                    }
                }

                DB::commit();

                return $this->success('Establishments synced successfully', [
                    'establishment_synced' => $establishmentSynced,
                    'sabeel_synced'       => $sabeelSynced,
                    'owner_mappings_synced' => $ownerMappingsSynced,
                    'total_records'       => count($data['data'])
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Establishment sync failed');
        }
    }
}
