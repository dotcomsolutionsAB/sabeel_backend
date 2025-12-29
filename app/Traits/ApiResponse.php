<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait ApiResponse
{
    protected function success(string $message, $data = [], int $code = 200, array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'code'   => $code,
            'status' => 'success',
            'message'=> $message,
            'data'   => $data ?? [],
        ], $extra);

        return response()->json($payload, $code);
    }

    protected function error(string $message, int $code = 400, array $extra = []): JsonResponse
    {
        $payload = array_merge([
            'code'   => $code,
            'status' => 'error',
            'message'=> $message,
        ], $extra);

        return response()->json($payload, $code);
    }

    protected function validation($validator): JsonResponse
    {
        // Your required format:
        // { code: 422, status: "error", message: ["Error message"] }
        // We'll return ALL errors flattened as array.
        // $all = collect($validator->errors()->all())->values()->all();
        // Get ONLY the first error message
        $firstError = $validator->errors()->first();

        return response()->json([
            'code'   => 422,
            'status' => 'error',
            'message'=> $firstError,
        ], 422);
    }

    protected function serverError(\Throwable $e, string $logTitle = 'API error'): JsonResponse
    {
        Log::error($logTitle, [
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ]);

        // As per your instruction: log error, don't expose in response
        return $this->error('Something went wrong.', 500);
    }
}
