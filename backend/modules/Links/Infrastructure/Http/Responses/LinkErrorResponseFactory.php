<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Modules\Links\Exceptions\IdempotencyKeyReused;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugUnavailable;

final class LinkErrorResponseFactory
{
    public function aliasUnavailable(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 409,
            code: 'ALIAS_UNAVAILABLE',
            message: 'The requested alias is unavailable.',
            requestId: $requestId,
        );
    }

    public function slugGenerationFailed(int $retryAfter, ?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 503,
            code: SlugGenerationExhausted::ERROR_CODE,
            message: 'Automatic slug generation failed. Please try again.',
            requestId: $requestId,
            retryAfter: max(1, $retryAfter),
        );
    }

    public function rateLimitExceeded(int $retryAfter, ?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 429,
            code: 'RATE_LIMIT_EXCEEDED',
            message: 'Too many requests.',
            requestId: $requestId,
            retryAfter: max(1, $retryAfter),
        );
    }

    public function serviceUnavailable(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 503,
            code: 'SERVICE_UNAVAILABLE',
            message: 'The service is temporarily unavailable.',
            requestId: $requestId,
        );
    }

    public function idempotencyKeyReused(
        ?IdempotencyKeyReused $exception = null,
        ?string $requestId = null,
    ): JsonResponse {
        return $this->errorResponse(
            status: 409,
            code: IdempotencyKeyReused::ERROR_CODE,
            message: 'The idempotency key was used with a different request.',
            requestId: $requestId,
        );
    }

    public function fromSlugUnavailable(SlugUnavailable $exception, ?string $requestId = null): JsonResponse
    {
        return $this->aliasUnavailable($requestId);
    }

    public function fromSlugGenerationExhausted(SlugGenerationExhausted $exception, int $retryAfter = 1, ?string $requestId = null): JsonResponse
    {
        return $this->slugGenerationFailed($retryAfter, $requestId);
    }

    private function errorResponse(
        int $status,
        string $code,
        string $message,
        ?string $requestId,
        ?int $retryAfter = null,
    ): JsonResponse {
        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => $requestId ?? 'stub-request-id',
        ];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response()->json([
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId ?? 'stub-request-id',
        ], $status)->withHeaders($headers);
    }
}
