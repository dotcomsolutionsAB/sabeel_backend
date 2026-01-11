<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Models\MumineenModel;
use App\Models\MumineenSabeelModel;
use App\Models\YearModel;
use App\Models\ImportLogModel;
use App\Models\ReceiptModel;
use App\Models\MumineenEstablishmentModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportController extends Controller
{
    use ApiResponse;

    /**
     * Dry run - Analyze Excel file and return report of changes
     * POST /import/mumineen/dry-run
     */
    public function dryRun(Request $request)
    {
        try {
            // Increase execution time and memory for large files
            set_time_limit(600); // 10 minutes
            ini_set('memory_limit', '512M');
            
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls|max:10240', // 10MB max
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();

            // Read Excel file using PhpSpreadsheet directly
            try {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
            } catch (\Exception $e) {
                Log::error('Excel read error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                return $this->error('Failed to read Excel file: ' . $e->getMessage(), 422);
            }
            
            if (empty($rows) || count($rows) < 2) {
                return $this->error('Excel file is empty or invalid (needs at least header and one data row)', 422);
            }

            $headers = array_shift($rows); // Remove header row
            $data = $rows;
            
            if (empty($data)) {
                return $this->error('Excel file contains no data rows', 422);
            }
            
            if (empty($headers)) {
                return $this->error('Excel file headers are missing', 422);
            }

            // Map column indices
            $columnMap = $this->mapColumns($headers);

            // Group rows by HOF_ID (families)
            $families = $this->groupByFamily($data, $columnMap);

            // Analyze changes
            $report = $this->analyzeChanges($families, $columnMap);

            // Create log entry
            $log = ImportLogModel::create([
                'operation_type' => 'dry_run',
                'file_name' => $fileName,
                'total_records' => count($data),
                'hof_found' => $report['hof_found'],
                'hof_updated' => $report['hof_to_update'],
                'hof_created' => $report['hof_to_create'],
                'fm_synced' => $report['fm_to_sync'],
                'fm_added' => $report['fm_to_add'],
                'fm_removed' => $report['fm_to_remove'],
                'sabeel_created' => $report['sabeel_to_create'],
                'errors' => count($report['errors']),
                'details' => $report['details'],
                'error_log' => $report['errors'],
                'status' => 'completed',
                'user_id' => auth()->check() ? auth()->id() : null,
            ]);

            return $this->success('Dry run analysis completed', [
                'log_id' => $log->id,
                'summary' => [
                    'total_records' => count($data),
                    'total_families' => count($families),
                    'hof_found' => $report['hof_found'],
                    'hof_to_update' => $report['hof_to_update'],
                    'hof_to_create' => $report['hof_to_create'],
                    'fm_to_add' => $report['fm_to_add'],
                    'fm_to_remove' => $report['fm_to_remove'],
                    'sabeel_to_create' => $report['sabeel_to_create'],
                    'errors' => count($report['errors']),
                ],
                'details' => $report['details'],
                'errors' => $report['errors'],
            ], 200);

        } catch (\Throwable $e) {
            // Log detailed error information
            Log::error('Dry run failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
            ]);
            
            // In development, return more details
            if (config('app.debug')) {
                return $this->error('Dry run failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
            }
            
            return $this->serverError($e, 'Dry run failed');
        }
    }

    /**
     * Execute import - Actually perform the import operations
     * POST /import/mumineen/execute
     */
    public function execute(Request $request)
    {
        try {
            // Increase execution time and memory for large files
            set_time_limit(600); // 10 minutes
            ini_set('memory_limit', '512M');
            
            $validator = Validator::make($request->all(), [
                'file' => 'required|file|mimes:xlsx,xls|max:10240', // 10MB max
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();

            // Create log entry
            $log = ImportLogModel::create([
                'operation_type' => 'import',
                'file_name' => $fileName,
                'status' => 'processing',
                'user_id' => auth()->check() ? auth()->id() : null,
            ]);

            try {
                // Read Excel file using PhpSpreadsheet directly
                try {
                    $spreadsheet = IOFactory::load($file->getRealPath());
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();
                } catch (\Exception $e) {
                    $log->update([
                        'status' => 'failed',
                        'error_log' => [['message' => 'Failed to read Excel file: ' . $e->getMessage()]],
                        'errors' => 1,
                    ]);
                    return $this->error('Failed to read Excel file: ' . $e->getMessage(), 422);
                }
                
                if (empty($rows) || count($rows) < 2) {
                    $log->update([
                        'status' => 'failed',
                        'error_log' => [['message' => 'Excel file is empty or invalid (needs at least header and one data row)']],
                        'errors' => 1,
                    ]);
                    return $this->error('Excel file is empty or invalid', 422);
                }

                $headers = array_shift($rows); // Remove header row
                $data = $rows;
                
                if (empty($data)) {
                    $log->update([
                        'status' => 'failed',
                        'error_log' => [['message' => 'Excel file contains no data rows']],
                        'errors' => 1,
                    ]);
                    return $this->error('Excel file contains no data rows', 422);
                }
                
                if (empty($headers)) {
                    $log->update([
                        'status' => 'failed',
                        'error_log' => [['message' => 'Excel file headers are missing']],
                        'errors' => 1,
                    ]);
                    return $this->error('Excel file headers are missing', 422);
                }

                // Map column indices
                $columnMap = $this->mapColumns($headers);

                // Group rows by Family_ID (families)
                $families = $this->groupByFamily($data, $columnMap);

                // Execute import
                $result = $this->performImport($families, $columnMap, $log);
                
                // Mark HOFs not in Excel as external
                $this->markExternalHofs($families, $columnMap);

                $log->update([
                    'status' => 'completed',
                    'total_records' => count($data),
                    'hof_found' => $result['hof_found'],
                    'hof_updated' => $result['hof_updated'],
                    'hof_created' => $result['hof_created'],
                    'fm_synced' => $result['fm_synced'],
                    'fm_added' => $result['fm_added'],
                    'fm_removed' => $result['fm_removed'],
                    'sabeel_created' => $result['sabeel_created'],
                    'errors' => count($result['errors']),
                    'details' => $result['details'],
                    'error_log' => $result['errors'],
                ]);

                return $this->success('Import completed successfully', [
                    'log_id' => $log->id,
                    'summary' => [
                        'total_records' => count($data),
                        'total_families' => count($families),
                        'hof_found' => $result['hof_found'],
                        'hof_updated' => $result['hof_updated'],
                        'hof_created' => $result['hof_created'],
                        'fm_added' => $result['fm_added'],
                        'fm_removed' => $result['fm_removed'],
                        'sabeel_created' => $result['sabeel_created'],
                        'errors' => count($result['errors']),
                    ],
                    'errors' => $result['errors'],
                    'details' => $result['details'] ?? [],
                ], 200);

            } catch (\Throwable $e) {
                $log->update([
                    'status' => 'failed',
                    'error_log' => [['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]],
                    'errors' => 1,
                ]);
                throw $e;
            }

        } catch (\Throwable $e) {
            // Log detailed error information
            Log::error('Import execution failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'previous' => $e->getPrevious() ? $e->getPrevious()->getMessage() : null,
            ]);
            
            // In development, return more details
            if (config('app.debug')) {
                return $this->error('Import execution failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
            }
            
            return $this->serverError($e, 'Import execution failed');
        }
    }

    /**
     * Map Excel columns to indices (handle variations in header names)
     */
    private function mapColumns(array $headers): array
    {
        $map = [];
        
        // Normalize column name mappings
        $columnMappings = [
            'its_id' => ['its_id', 'itsid', 'its'],
            'hof_fm_type' => ['hof_fm_type', 'hof_fmtype', 'hof/fm_type', 'type'],
            'hof_id' => ['hof_id', 'hofid', 'hof id'],
            'family_id' => ['family_id', 'familyid', 'family'],
            'full_name' => ['full_name', 'fullname', 'name'],
            'sector' => ['sector'],
            'sub_sector' => ['sub_sector', 'subsector', 'sub sector'],
            'mobile' => ['mobile', 'mobile_no', 'mobile_no.'],
            'email' => ['email', 'e-mail'],
            'gender' => ['gender', 'sex'],
            'age' => ['age'],
        ];
        
        foreach ($headers as $index => $header) {
            $normalizedHeader = trim(strtolower(str_replace([' ', '-'], '_', $header)));
            
            // Try exact match first
            $map[$normalizedHeader] = $index;
            
            // Try mapped variations
            foreach ($columnMappings as $key => $variations) {
                if (in_array($normalizedHeader, $variations)) {
                    $map[$key] = $index;
                    break;
                }
            }
        }
        
        return $map;
    }

    /**
     * Group rows by family (using HOF_ID - all members of a family share the same HOF_ID)
     */
    private function groupByFamily(array $data, array $columnMap): array
    {
        $families = [];
        
        foreach ($data as $row) {
            // Use HOF_ID to group families - all family members (HOF and FM) share the same HOF_ID
            $hofId = $this->getValue($row, $columnMap, 'hof_id') ?? '';
            
            if (empty($hofId)) {
                // If no HOF_ID, skip this row (or use ITS_ID as fallback for HOF only)
                $hofType = strtoupper(trim($this->getValue($row, $columnMap, 'hof_fm_type') ?? ''));
                if ($hofType === 'HOF') {
                    // For HOF without HOF_ID, use their own ITS_ID
                    $its = $this->getValue($row, $columnMap, 'its_id') ?? '';
                    if (!empty($its)) {
                        $hofId = $its;
                    } else {
                        continue; // Skip invalid row
                    }
                } else {
                    continue; // Skip FM without HOF_ID
                }
            }
            
            if (!isset($families[$hofId])) {
                $families[$hofId] = [];
            }
            
            $families[$hofId][] = $row;
        }
        
        return $families;
    }

    /**
     * Get value from row using column map
     */
    private function getValue(array $row, array $columnMap, string $columnName): ?string
    {
        $columnName = trim(strtolower($columnName));
        
        // Try exact match first
        if (isset($columnMap[$columnName])) {
            $index = $columnMap[$columnName];
            return isset($row[$index]) ? trim((string)$row[$index]) : null;
        }
        
        // Try normalized variations
        $normalized = str_replace([' ', '-'], '_', $columnName);
        if (isset($columnMap[$normalized])) {
            $index = $columnMap[$normalized];
            return isset($row[$index]) ? trim((string)$row[$index]) : null;
        }
        
        return null;
    }

    /**
     * Analyze changes (dry run)
     */
    private function analyzeChanges(array $families, array $columnMap): array
    {
        $report = [
            'hof_found' => 0,
            'hof_to_update' => 0,
            'hof_to_create' => 0,
            'fm_to_sync' => 0,
            'fm_to_add' => 0,
            'fm_to_remove' => 0,
            'sabeel_to_create' => 0,
            'details' => [],
            'errors' => [],
        ];

        foreach ($families as $familyId => $members) {
            try {
                // Find HOF in family
                $hofRow = null;
                foreach ($members as $member) {
                    $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                    if ($hofType === 'HOF') {
                        $hofRow = $member;
                        break;
                    }
                }

                if (!$hofRow) {
                    $report['errors'][] = [
                        'family_id' => $familyId,
                        'message' => 'No HOF found in family',
                    ];
                    continue;
                }

                $hofIts = $this->getValue($hofRow, $columnMap, 'its_id');
                if (empty($hofIts)) {
                    $report['errors'][] = [
                        'family_id' => $familyId,
                        'message' => 'HOF ITS_ID is empty',
                    ];
                    continue;
                }

                // Check if HOF exists
                $existingHof = MumineenModel::where('its', $hofIts)->where('hof_type', 'HOF')->first();

                if ($existingHof) {
                    // HOF found - analyze updates
                    $report['hof_found']++;
                    $updates = [];
                    
                    $name = $this->getValue($hofRow, $columnMap, 'full_name');
                    if ($name && $name !== $existingHof->name) {
                        $updates['name'] = $name;
                    }
                    
                    $sector = $this->getValue($hofRow, $columnMap, 'sector');
                    if ($sector && $sector !== $existingHof->sector) {
                        $updates['sector'] = $sector;
                    }
                    
                    if (!empty($updates)) {
                        $report['hof_to_update']++;
                    }

                    // Analyze FM sync
                    $existingFms = MumineenModel::where('family_id', $existingHof->family_id)
                        ->where('hof_type', 'FM')
                        ->pluck('its')
                        ->toArray();

                    $excelFmIts = [];
                    foreach ($members as $member) {
                        $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                        if ($hofType === 'FM') {
                            $its = $this->getValue($member, $columnMap, 'its_id');
                            if (!empty($its)) {
                                $excelFmIts[] = $its;
                            }
                        }
                    }

                    $toAdd = array_diff($excelFmIts, $existingFms);
                    $toRemove = array_diff($existingFms, $excelFmIts);

                    $report['fm_to_add'] += count($toAdd);
                    $report['fm_to_remove'] += count($toRemove);
                    $report['fm_to_sync'] += count($toAdd) + count($toRemove);

                    $report['details'][] = [
                        'family_id' => $existingHof->family_id,
                        'hof_its' => $hofIts,
                        'action' => 'update',
                        'updates' => $updates,
                        'fm_to_add' => count($toAdd),
                        'fm_to_remove' => count($toRemove),
                    ];

                } else {
                    // HOF not found - check for FM
                    $fmFound = null;
                    foreach ($members as $member) {
                        $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                        if ($hofType === 'FM') {
                            $fmIts = $this->getValue($member, $columnMap, 'its_id');
                            if (!empty($fmIts)) {
                                $fmFound = MumineenModel::where('its', $fmIts)->where('hof_type', 'FM')->first();
                                if ($fmFound) {
                                    break;
                                }
                            }
                        }
                    }

                    if ($fmFound) {
                        // FM found - convert to HOF
                        $report['hof_to_update']++;
                        $report['fm_to_remove']++; // Remove old FM record
                        $report['fm_to_sync']++; // Will sync FMs

                        $excelFmCount = 0;
                        foreach ($members as $member) {
                            $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                            if ($hofType === 'FM') {
                                $its = $this->getValue($member, $columnMap, 'its_id');
                                if (!empty($its)) {
                                    $excelFmCount++;
                                }
                            }
                        }
                        $report['fm_to_add'] += $excelFmCount;

                        $report['details'][] = [
                            'family_id' => $fmFound->family_id,
                            'hof_its' => $hofIts,
                            'fm_its' => $fmFound->its,
                            'action' => 'convert_fm_to_hof',
                        ];

                    } else {
                        // New family - create HOF and sabeel
                        $report['hof_to_create']++;
                        $report['sabeel_to_create']++;

                        $excelFmCount = 0;
                        foreach ($members as $member) {
                            $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                            if ($hofType === 'FM') {
                                $its = $this->getValue($member, $columnMap, 'its_id');
                                if (!empty($its)) {
                                    $excelFmCount++;
                                }
                            }
                        }
                        $report['fm_to_add'] += $excelFmCount;

                        $report['details'][] = [
                            'hof_its' => $hofIts,
                            'action' => 'create_new',
                            'fm_to_add' => $excelFmCount,
                        ];
                    }
                }

            } catch (\Exception $e) {
                $report['errors'][] = [
                    'family_id' => $familyId,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * Perform actual import
     */
    private function performImport(array $families, array $columnMap, ImportLogModel $log): array
    {
        $result = [
            'hof_found' => 0,
            'hof_updated' => 0,
            'hof_created' => 0,
            'fm_synced' => 0,
            'fm_added' => 0,
            'fm_removed' => 0,
            'sabeel_created' => 0,
            'details' => [],
            'errors' => [],
        ];

        // Get current year
        $currentYear = $this->getCurrentYear();

        DB::beginTransaction();

        try {
            foreach ($families as $familyId => $members) {
                try {
                    // Find HOF in family
                    $hofRow = null;
                    foreach ($members as $member) {
                        $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                        if ($hofType === 'HOF') {
                            $hofRow = $member;
                            break;
                        }
                    }

                    if (!$hofRow) {
                        $result['errors'][] = [
                            'family_id' => $familyId,
                            'message' => 'No HOF found in family',
                        ];
                        continue;
                    }

                    $hofIts = $this->getValue($hofRow, $columnMap, 'its_id');
                    if (empty($hofIts)) {
                        $result['errors'][] = [
                            'family_id' => $familyId,
                            'message' => 'HOF ITS_ID is empty',
                        ];
                        continue;
                    }

                    // Check if HOF exists
                    $existingHof = MumineenModel::where('its', $hofIts)->where('hof_type', 'HOF')->first();

                    if ($existingHof) {
                        // HOF found - update and sync FMs
                        $result['hof_found']++;
                        $this->updateHof($existingHof, $hofRow, $columnMap);
                        $result['hof_updated']++;

                        $this->syncFamilyMembers($existingHof->family_id, $members, $columnMap, $result);

                    } else {
                        // HOF not found - check for FM
                        $fmFound = null;
                        foreach ($members as $member) {
                            $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                            if ($hofType === 'FM') {
                                $fmIts = $this->getValue($member, $columnMap, 'its_id');
                                if (!empty($fmIts)) {
                                    $fmFound = MumineenModel::where('its', $fmIts)->where('hof_type', 'FM')->first();
                                    if ($fmFound) {
                                        break;
                                    }
                                }
                            }
                        }

                        if ($fmFound) {
                            // Convert FM to HOF
                            $familyId = $fmFound->family_id;
                            $this->convertFmToHof($fmFound, $hofRow, $columnMap);
                            $result['hof_updated']++;
                            $result['fm_removed']++;

                            $existingHof = MumineenModel::where('family_id', $familyId)->where('hof_type', 'HOF')->first();
                            $this->syncFamilyMembers($familyId, $members, $columnMap, $result);

                        } else {
                            // Create new HOF
                            $familyId = $this->generateUniqueFamilyId();
                            $hof = $this->createHof($hofRow, $columnMap, $familyId);
                            $result['hof_created']++;

                            // Create sabeel for current year
                            if ($currentYear) {
                                $this->createSabeelForCurrentYear($familyId, $currentYear);
                                $result['sabeel_created']++;
                            }

                            // Sync family members
                            $this->syncFamilyMembers($familyId, $members, $columnMap, $result);
                        }
                    }

                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'family_id' => $familyId,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Update HOF record
     */
    private function updateHof(MumineenModel $hof, array $hofRow, array $columnMap): void
    {
        $updates = [];

        $name = $this->getValue($hofRow, $columnMap, 'full_name');
        if ($name) {
            $updates['name'] = $name;
        }

        $sector = $this->getValue($hofRow, $columnMap, 'sector');
        if ($sector !== null) {
            $updates['sector'] = $sector;
        }

        $subSector = $this->getValue($hofRow, $columnMap, 'sub_sector');
        if ($subSector !== null) {
            $updates['sub_sector'] = $subSector;
        }

        $mobile = $this->getValue($hofRow, $columnMap, 'mobile');
        if ($mobile !== null) {
            $updates['mobile'] = substr($mobile, -10); // Rightmost 10 digits
        }

        $email = $this->getValue($hofRow, $columnMap, 'email');
        if ($email !== null) {
            $updates['email'] = $email;
        }

        $gender = strtolower(trim($this->getValue($hofRow, $columnMap, 'gender') ?? ''));
        if (in_array($gender, ['male', 'female'])) {
            $updates['gender'] = $gender;
        }

        $age = $this->getValue($hofRow, $columnMap, 'age');
        if ($age !== null && $age !== '' && $age !== '0') {
            $updates['age'] = (int) $age;
        }

        if (!empty($updates)) {
            $hof->update($updates);
        }
    }

    /**
     * Convert FM to HOF
     */
    private function convertFmToHof(MumineenModel $fm, array $hofRow, array $columnMap): void
    {
        $familyId = $fm->family_id;

        // Delete old FM record
        $fm->delete();

        // Create HOF record
        $this->createHof($hofRow, $columnMap, $familyId);
    }

    /**
     * Create new HOF
     */
    private function createHof(array $hofRow, array $columnMap, int $familyId): MumineenModel
    {
        $name = $this->getValue($hofRow, $columnMap, 'full_name') ?? '';
        $its = $this->getValue($hofRow, $columnMap, 'its_id') ?? '';
        $sector = $this->getValue($hofRow, $columnMap, 'sector');
        $subSector = $this->getValue($hofRow, $columnMap, 'sub_sector');
        $mobile = $this->getValue($hofRow, $columnMap, 'mobile');
        $email = $this->getValue($hofRow, $columnMap, 'email');
        $gender = strtolower(trim($this->getValue($hofRow, $columnMap, 'gender') ?? 'male'));
        if (!in_array($gender, ['male', 'female'])) {
            $gender = 'male';
        }
        $age = $this->getValue($hofRow, $columnMap, 'age');
        
        $mobile = $mobile ? substr($mobile, -10) : null;
        $age = ($age && $age !== '' && $age !== '0') ? (int) $age : null;
        $picUrl = url('storage/uploads/its_images/placeholder.jpg');

        return MumineenModel::create([
            'family_id' => $familyId,
            'hof_type' => 'HOF',
            'its' => $its,
            'hof_its' => null,
            'family_its' => null,
            'name' => $name,
            'sector' => $sector,
            'sub_sector' => $subSector,
            'mobile' => $mobile,
            'email' => $email,
            'gender' => $gender,
            'age' => $age,
            'pic' => $picUrl,
            'status' => 'active',
            'external' => false, // New HOFs from Excel are not external
        ]);
    }

    /**
     * Sync family members
     */
    private function syncFamilyMembers(int $familyId, array $members, array $columnMap, array &$result): void
    {
        // Get existing FMs
        $existingFms = MumineenModel::where('family_id', $familyId)
            ->where('hof_type', 'FM')
            ->get()
            ->keyBy('its');

        // Get Excel FMs (excluding HOF)
        $excelFms = [];
        foreach ($members as $member) {
            $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
            if ($hofType === 'FM') {
                $its = $this->getValue($member, $columnMap, 'its_id');
                if (!empty($its)) {
                    $excelFms[$its] = $member;
                }
            }
        }

        // Log for debugging
        $result['details'][] = [
            'type' => 'sync_fm_debug',
            'family_id' => $familyId,
            'existing_fm_count' => count($existingFms),
            'excel_fm_count' => count($excelFms),
            'existing_fm_its' => array_keys($existingFms->toArray()),
            'excel_fm_its' => array_keys($excelFms),
        ];

        // Add new FMs
        foreach ($excelFms as $its => $memberRow) {
            if (!isset($existingFms[$its])) {
                try {
                    $this->createFamilyMember($memberRow, $columnMap, $familyId);
                    $result['fm_added']++;
                    $result['fm_synced']++;
                    
                    // Log successful creation
                    $result['details'][] = [
                        'type' => 'fm_created',
                        'family_id' => $familyId,
                        'its' => $its,
                        'name' => $this->getValue($memberRow, $columnMap, 'full_name'),
                    ];
                } catch (\Exception $e) {
                    // Log error if FM creation fails (e.g., duplicate ITS constraint)
                    $result['errors'][] = [
                        'family_id' => $familyId,
                        'its' => $its,
                        'message' => 'Failed to create FM: ' . $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 500), // Limit trace length
                    ];
                    
                    // Also log to Laravel log
                    Log::error('FM creation failed', [
                        'family_id' => $familyId,
                        'its' => $its,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            } else {
                // Update existing FM
                $this->updateFamilyMember($existingFms[$its], $memberRow, $columnMap);
            }
        }

        // Remove FMs not in Excel
        foreach ($existingFms as $its => $fm) {
            if (!isset($excelFms[$its])) {
                $fm->delete();
                $result['fm_removed']++;
                $result['fm_synced']++;
            }
        }
    }

    /**
     * Create family member
     */
    private function createFamilyMember(array $memberRow, array $columnMap, int $familyId): void
    {
        $name = $this->getValue($memberRow, $columnMap, 'full_name') ?? '';
        $its = $this->getValue($memberRow, $columnMap, 'its_id') ?? '';
        
        // Skip if ITS is empty (throw exception so caller knows it failed)
        if (empty($its)) {
            throw new \Exception('ITS_ID is empty for FM');
        }
        
        $sector = $this->getValue($memberRow, $columnMap, 'sector');
        $subSector = $this->getValue($memberRow, $columnMap, 'sub_sector');
        $mobile = $this->getValue($memberRow, $columnMap, 'mobile');
        $email = $this->getValue($memberRow, $columnMap, 'email');
        $gender = strtolower(trim($this->getValue($memberRow, $columnMap, 'gender') ?? 'male'));
        if (!in_array($gender, ['male', 'female'])) {
            $gender = 'male';
        }
        $age = $this->getValue($memberRow, $columnMap, 'age');
        
        $mobile = $mobile ? substr($mobile, -10) : null;
        $age = ($age && $age !== '' && $age !== '0') ? (int) $age : null;
        $picUrl = url('storage/uploads/its_images/placeholder.jpg');

        // Check if ITS already exists globally before attempting to create
        $existingRecord = MumineenModel::where('its', $its)->first();
        if ($existingRecord) {
            throw new \Exception("ITS {$its} already exists in database (as {$existingRecord->hof_type}, family_id: {$existingRecord->family_id})");
        }

        try {
            // Create the FM record
            MumineenModel::create([
                'family_id' => $familyId,
                'hof_type' => 'FM',
                'its' => $its,
                'hof_its' => null,
                'family_its' => null,
                'name' => $name,
                'sector' => $sector,
                'sub_sector' => $subSector,
                'mobile' => $mobile,
                'email' => $email,
                'gender' => $gender,
                'age' => $age,
                'pic' => $picUrl,
                'status' => 'active',
            ]);
        } catch (\Exception $e) {
            // Log the actual database error
            Log::error('FM create database error', [
                'family_id' => $familyId,
                'its' => $its,
                'name' => $name,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Update family member
     */
    private function updateFamilyMember(MumineenModel $fm, array $memberRow, array $columnMap): void
    {
        $updates = [];

        $name = $this->getValue($memberRow, $columnMap, 'full_name');
        if ($name) {
            $updates['name'] = $name;
        }

        $sector = $this->getValue($memberRow, $columnMap, 'sector');
        if ($sector !== null) {
            $updates['sector'] = $sector;
        }

        $subSector = $this->getValue($memberRow, $columnMap, 'sub_sector');
        if ($subSector !== null) {
            $updates['sub_sector'] = $subSector;
        }

        $mobile = $this->getValue($memberRow, $columnMap, 'mobile');
        if ($mobile !== null) {
            $updates['mobile'] = substr($mobile, -10);
        }

        $email = $this->getValue($memberRow, $columnMap, 'email');
        if ($email !== null) {
            $updates['email'] = $email;
        }

        $gender = strtolower(trim($this->getValue($memberRow, $columnMap, 'gender') ?? ''));
        if (in_array($gender, ['male', 'female'])) {
            $updates['gender'] = $gender;
        }

        $age = $this->getValue($memberRow, $columnMap, 'age');
        if ($age !== null && $age !== '' && $age !== '0') {
            $updates['age'] = (int) $age;
        }

        if (!empty($updates)) {
            $fm->update($updates);
        }
    }

    /**
     * Create sabeel for current year
     */
    private function createSabeelForCurrentYear(int $familyId, string $year): void
    {
        // Check if sabeel already exists
        $exists = MumineenSabeelModel::where('family_id', $familyId)
            ->where('year', $year)
            ->exists();

        if (!$exists) {
            $updatedBy = auth()->check() ? auth()->id() : 1;
            MumineenSabeelModel::create([
                'family_id' => $familyId,
                'year' => $year,
                'sabeel' => 0, // Default sabeel amount
                'updated_by' => $updatedBy,
            ]);
        }
    }

    /**
     * Get current year
     */
    private function getCurrentYear(): ?string
    {
        $currentYear = YearModel::where('is_current', 1)->value('year');
        if ($currentYear) {
            return $currentYear;
        }
        $maxYear = YearModel::orderBy('year', 'desc')->value('year');
        return $maxYear;
    }

    /**
     * Generate unique family ID
     */
    private function generateUniqueFamilyId(): int
    {
        do {
            $familyId = random_int(1000000000, 9999999999);
        } while (MumineenModel::where('family_id', $familyId)->exists());

        return $familyId;
    }

    /**
     * Mark HOFs not in Excel as external
     */
    private function markExternalHofs(array $families, array $columnMap): void
    {
        // Get all HOF ITS from Excel file
        $excelHofIts = [];
        foreach ($families as $members) {
            foreach ($members as $member) {
                $hofType = strtoupper(trim($this->getValue($member, $columnMap, 'hof_fm_type') ?? ''));
                if ($hofType === 'HOF') {
                    $its = $this->getValue($member, $columnMap, 'its_id');
                    if (!empty($its)) {
                        $excelHofIts[] = $its;
                    }
                    break; // Only one HOF per family
                }
            }
        }

        // Update external flag based on whether HOF is in Excel
        if (!empty($excelHofIts)) {
            // Reset external = false for HOFs in Excel
            MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->whereIn('its', $excelHofIts)
                ->update(['external' => false]);

            // Mark external = true for HOFs not in Excel
            MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->whereNotIn('its', $excelHofIts)
                ->update(['external' => true]);
        } else {
            // If no Excel HOFs, mark all active HOFs as external
            MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->update(['external' => true]);
        }
    }

    /**
     * Get list of external families (HOFs active but not in Excel)
     * POST /import/mumineen/external-families
     */
    public function getExternalFamilies(Request $request)
    {
        try {
            $limit = max(1, (int) $request->input('limit', 50));
            $offset = max(0, (int) $request->input('offset', 0));
            $search = trim((string) $request->input('search', ''));

            // Get external HOFs (active HOFs with external = true)
            $query = MumineenModel::where('hof_type', 'HOF')
                ->where('status', 'active')
                ->where('external', true)
                ->orderBy('name', 'asc');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('its', 'like', "%{$search}%")
                        ->orWhere('sector', 'like', "%{$search}%");
                });
            }

            $total = $query->count();
            $hofs = $query->skip($offset)->take($limit)->get();

            $families = [];
            foreach ($hofs as $hof) {
                // Get family members count
                $fmCount = MumineenModel::where('family_id', $hof->family_id)
                    ->where('hof_type', 'FM')
                    ->where('status', 'active')
                    ->count();

                $families[] = [
                    'family_id' => (string) $hof->family_id,
                    'hof_its' => $hof->its,
                    'hof_name' => $hof->name,
                    'sector' => $hof->sector ?? '',
                    'sub_sector' => $hof->sub_sector ?? '',
                    'mobile' => $hof->mobile ?? '',
                    'email' => $hof->email ?? '',
                    'fm_count' => $fmCount,
                ];
            }

            return $this->success('External families fetched successfully', $families, 200, [
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($families),
                    'total' => $total,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Get external families failed');
        }
    }

    /**
     * Dry run - Analyze family merge operation
     * POST /import/mumineen/merge-family/dry-run
     */
    public function mergeFamilyDryRun(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'external_family_id' => 'required|integer',
                'current_family_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $externalFamilyId = (int) $request->input('external_family_id');
            $currentFamilyId = (int) $request->input('current_family_id');

            // Validate both families exist and are HOF
            $externalHof = MumineenModel::where('family_id', $externalFamilyId)
                ->where('hof_type', 'HOF')
                ->first();

            $currentHof = MumineenModel::where('family_id', $currentFamilyId)
                ->where('hof_type', 'HOF')
                ->first();

            if (!$externalHof) {
                return $this->error('External family not found or not a HOF', 404);
            }

            if (!$currentHof) {
                return $this->error('Current family not found or not a HOF', 404);
            }

            if (!$externalHof->external) {
                return $this->error('External family must have external = true', 422);
            }

            if ($externalFamilyId === $currentFamilyId) {
                return $this->error('External family ID and current family ID cannot be the same', 422);
            }

            // Analyze changes
            $receiptsCount = ReceiptModel::where('family_id', $externalFamilyId)->count();
            $sabeelCount = MumineenSabeelModel::where('family_id', $externalFamilyId)->count();
            $establishmentCount = MumineenEstablishmentModel::where('family_id', $externalFamilyId)->count();
            $mumineenCount = MumineenModel::where('family_id', $externalFamilyId)->count();

            // Get family member details
            $externalFamilyMembers = MumineenModel::where('family_id', $externalFamilyId)->get();
            $members = [];
            foreach ($externalFamilyMembers as $member) {
                $members[] = [
                    'id' => $member->id,
                    'its' => $member->its,
                    'name' => $member->name,
                    'hof_type' => $member->hof_type,
                ];
            }

            // Create log entry
            $log = ImportLogModel::create([
                'operation_type' => 'merge_family_dry_run',
                'file_name' => "Merge {$externalFamilyId} to {$currentFamilyId}",
                'total_records' => $mumineenCount,
                'status' => 'completed',
                'user_id' => auth()->check() ? auth()->id() : null,
                'details' => [
                    'external_family_id' => $externalFamilyId,
                    'current_family_id' => $currentFamilyId,
                    'external_hof_name' => $externalHof->name,
                    'external_hof_its' => $externalHof->its,
                    'current_hof_name' => $currentHof->name,
                    'current_hof_its' => $currentHof->its,
                    'receipts_to_update' => $receiptsCount,
                    'sabeel_to_update' => $sabeelCount,
                    'establishment_to_update' => $establishmentCount,
                    'mumineen_to_delete' => $mumineenCount,
                    'members' => $members,
                ],
            ]);

            return $this->success('Merge family dry run analysis completed', [
                'log_id' => $log->id,
                'external_family_id' => $externalFamilyId,
                'current_family_id' => $currentFamilyId,
                'external_hof' => [
                    'its' => $externalHof->its,
                    'name' => $externalHof->name,
                    'sector' => $externalHof->sector,
                ],
                'current_hof' => [
                    'its' => $currentHof->its,
                    'name' => $currentHof->name,
                    'sector' => $currentHof->sector,
                ],
                'summary' => [
                    'receipts_to_update' => $receiptsCount,
                    'sabeel_to_update' => $sabeelCount,
                    'establishment_to_update' => $establishmentCount,
                    'mumineen_to_delete' => $mumineenCount,
                    'total_records_affected' => $receiptsCount + $sabeelCount + $establishmentCount + $mumineenCount,
                ],
                'members_to_delete' => $members,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Merge family dry run failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('app.debug')) {
                return $this->error('Merge family dry run failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
            }

            return $this->serverError($e, 'Merge family dry run failed');
        }
    }

    /**
     * Execute family merge
     * POST /import/mumineen/merge-family/execute
     */
    public function mergeFamilyExecute(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'external_family_id' => 'required|integer',
                'current_family_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return $this->validation($validator);
            }

            $externalFamilyId = (int) $request->input('external_family_id');
            $currentFamilyId = (int) $request->input('current_family_id');

            // Validate both families exist and are HOF
            $externalHof = MumineenModel::where('family_id', $externalFamilyId)
                ->where('hof_type', 'HOF')
                ->first();

            $currentHof = MumineenModel::where('family_id', $currentFamilyId)
                ->where('hof_type', 'HOF')
                ->first();

            if (!$externalHof) {
                return $this->error('External family not found or not a HOF', 404);
            }

            if (!$currentHof) {
                return $this->error('Current family not found or not a HOF', 404);
            }

            if (!$externalHof->external) {
                return $this->error('External family must have external = true', 422);
            }

            if ($externalFamilyId === $currentFamilyId) {
                return $this->error('External family ID and current family ID cannot be the same', 422);
            }

            // Create log entry
            $log = ImportLogModel::create([
                'operation_type' => 'merge_family_execute',
                'file_name' => "Merge {$externalFamilyId} to {$currentFamilyId}",
                'status' => 'processing',
                'user_id' => auth()->check() ? auth()->id() : null,
            ]);

            try {
                DB::beginTransaction();

                // Count records before update
                $receiptsCount = ReceiptModel::where('family_id', $externalFamilyId)->count();
                $sabeelCount = MumineenSabeelModel::where('family_id', $externalFamilyId)->count();
                $establishmentCount = MumineenEstablishmentModel::where('family_id', $externalFamilyId)->count();
                $mumineenCount = MumineenModel::where('family_id', $externalFamilyId)->count();

                // 1. Update receipts table
                ReceiptModel::where('family_id', $externalFamilyId)
                    ->update(['family_id' => $currentFamilyId]);

                // 2. Update sabeel table
                MumineenSabeelModel::where('family_id', $externalFamilyId)
                    ->update(['family_id' => $currentFamilyId]);

                // 3. Update establishment mapping table
                MumineenEstablishmentModel::where('family_id', $externalFamilyId)
                    ->update(['family_id' => $currentFamilyId]);

                // 4. Delete all mumineen records with external_family_id
                MumineenModel::where('family_id', $externalFamilyId)->delete();

                DB::commit();

                // Update log
                $log->update([
                    'status' => 'completed',
                    'total_records' => $mumineenCount,
                    'details' => [
                        'external_family_id' => $externalFamilyId,
                        'current_family_id' => $currentFamilyId,
                        'external_hof_name' => $externalHof->name,
                        'external_hof_its' => $externalHof->its,
                        'current_hof_name' => $currentHof->name,
                        'current_hof_its' => $currentHof->its,
                        'receipts_updated' => $receiptsCount,
                        'sabeel_updated' => $sabeelCount,
                        'establishment_updated' => $establishmentCount,
                        'mumineen_deleted' => $mumineenCount,
                    ],
                ]);

                return $this->success('Family merge completed successfully', [
                    'log_id' => $log->id,
                    'external_family_id' => $externalFamilyId,
                    'current_family_id' => $currentFamilyId,
                    'summary' => [
                        'receipts_updated' => $receiptsCount,
                        'sabeel_updated' => $sabeelCount,
                        'establishment_updated' => $establishmentCount,
                        'mumineen_deleted' => $mumineenCount,
                        'total_records_affected' => $receiptsCount + $sabeelCount + $establishmentCount + $mumineenCount,
                    ],
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                $log->update([
                    'status' => 'failed',
                    'error_log' => [['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]],
                    'errors' => 1,
                ]);
                throw $e;
            }

        } catch (\Throwable $e) {
            Log::error('Merge family execute failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (config('app.debug')) {
                return $this->error('Merge family execute failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
            }

            return $this->serverError($e, 'Merge family execute failed');
        }
    }
}
