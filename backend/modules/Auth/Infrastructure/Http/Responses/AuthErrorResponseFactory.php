<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Exceptions\ResourceNotFoundException;

final class AuthErrorResponseFactory
{
    public function unauthenticated(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 401,
            code: AuthTokenException::UNAUTHENTICATED,
            message: 'Authentication is required.',
            requestId: $requestId,
        );
    }

    public function tokenRestricted(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 403,
            code: AuthTokenException::TOKEN_RESTRICTED,
            message: 'This token cannot perform the requested operation.',
            requestId: $requestId,
        );
    }

    public function accountSuspended(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 403,
            code: AuthTokenException::ACCOUNT_SUSPENDED,
            message: 'The account is suspended.',
            requestId: $requestId,
        );
    }

    public function accountPendingDeletion(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 403,
            code: AuthTokenException::ACCOUNT_PENDING_DELETION,
            message: 'The account is pending deletion.',
            requestId: $requestId,
        );
    }

    public function resourceNotFound(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 404,
            code: ResourceNotFoundException::RESOURCE_NOT_FOUND,
            message: 'The requested resource was not found.',
            requestId: $requestId,
        );
    }

    public function rateLimitExceeded(int $retryAfter, ?string $requestId = null): JsonResponse
    {
        return response()->json([
            'code' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Too many requests.',
            'request_id' => $requestId ?? 'stub-request-id',
        ], 429)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'Retry-After' => (string) max(0, $retryAfter),
        ]);
    }

    public function registrationNotAllowed(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 403,
            code: 'REGISTRATION_NOT_ALLOWED',
            message: 'Registration is not available for these details.',
            requestId: $requestId,
        );
    }

    public function invalidCredentials(?string $requestId = null): JsonResponse
    {
        return $this->errorResponse(
            status: 401,
            code: 'INVALID_CREDENTIALS',
            message: 'The provided credentials are invalid.',
            requestId: $requestId,
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

    public function fromAuthTokenException(AuthTokenException $exception, ?string $requestId = null): JsonResponse
    {
        return match ($exception->errorCode()) {
            AuthTokenException::ACCOUNT_SUSPENDED => $this->accountSuspended($requestId),
            AuthTokenException::ACCOUNT_PENDING_DELETION => $this->accountPendingDeletion($requestId),
            AuthTokenException::TOKEN_RESTRICTED => $this->tokenRestricted($requestId),
            default => $this->unauthenticated($requestId),
        };
    }

    private function errorResponse(int $status, string $code, string $message, ?string $requestId): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId ?? 'stub-request-id',
        ], $status)->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
