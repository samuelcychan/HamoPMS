<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return self::success($paginator->items(), 200, [
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
