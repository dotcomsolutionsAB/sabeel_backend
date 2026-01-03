<?php

namespace App\Helpers;

use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExcelExportHelper
{
    /**
     * Store excel file and return public URL
     *
     * @param mixed  $export     Export object
     * @param string $folder     dashboard | family | receipt
     * @param string $filePrefix file name prefix
     */
    public static function store($export, string $folder, string $filePrefix)
    {
        $timestamp = now()->format('Ymd_His');
        $fileName  = "{$filePrefix}_{$timestamp}.xlsx";

        $path = "uploads/{$folder}/{$fileName}";

        // ensure directory exists
        Storage::disk('public')->makeDirectory("uploads/{$folder}");

        // store file
        Excel::store($export, $path, 'public');

        return response()->json([
            'code'    => 200,
            'status'  => 'success',
            'message' => 'Excel exported successfully',
            'data'    => [
                'file' => asset("storage/{$path}")
            ]
        ]);
    }
}
