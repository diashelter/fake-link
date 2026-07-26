<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    /**
     * @param  array<string, list<array{code: string, message: string}>>  $errors
     */
    public static function validationError(array $errors, ?string $requestId = null): JsonResponse
    {
        return response()->json([
            'code' => 'VALIDATION_FAILED',
            'message' => 'The given data was invalid.',
            'request_id' => $requestId ?? 'stub-request-id',
            'errors' => $errors,
        ], Response::HTTP_UNPROCESSABLE_ENTITY)->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function malformedRequest(?string $requestId = null): JsonResponse
    {
        return response()->json([
            'code' => 'MALFORMED_REQUEST',
            'message' => 'The request is malformed.',
            'request_id' => $requestId ?? 'stub-request-id',
        ], Response::HTTP_BAD_REQUEST)->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
