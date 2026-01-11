<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Models\ImportLogModel;
use Illuminate\Http\Request;

class ImportLogController extends Controller
{
    use ApiResponse;

    /**
     * Fetch import logs with pagination
     * POST /import/logs/retrieve/{id?}
     */
    public function fetch(Request $request, $id = null)
    {
        try {
            // SINGLE
            if ($id !== null) {
                $log = ImportLogModel::find($id);

                if (!$log) {
                    return $this->error('Import log not found.', 404);
                }

                return $this->success('Data fetched successfully', $log, 200);
            }

            // LIST (with pagination)
            $limit = max(1, (int) $request->input('limit', 10));
            $offset = max(0, (int) $request->input('offset', 0));
            $operationType = trim((string) $request->input('operation_type', ''));
            $status = trim((string) $request->input('status', ''));

            $q = ImportLogModel::orderBy('id', 'desc');

            if ($operationType !== '') {
                $q->where('operation_type', $operationType);
            }

            if ($status !== '') {
                $q->where('status', $status);
            }

            $total = (clone $q)->count();

            $items = $q->skip($offset)->take($limit)->get();
            $count = $items->count();

            return $this->success('Data fetched successfully', $items, 200, [
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => $count,
                    'total' => $total,
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->serverError($e, 'Import log fetch failed');
        }
    }
}
