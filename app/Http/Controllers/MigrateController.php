<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Models\YearModel;
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
}
