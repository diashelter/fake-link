<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Responses;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Exceptions\InvalidPasswordResetTokenException;
use Modules\Auth\Exceptions\PasswordReusedException;

final class AuthValidationResponseFactory
{
    public function invalidPasswordResetToken(?string $requestId = null): JsonResponse
    {
        return ApiResponse::validationError([
            'token' => [
                [
                    'code' => 'INVALID',
                    'message' => InvalidPasswordResetTokenException::MESSAGE,
                ],
            ],
        ], $requestId);
    }

    public function passwordReused(?string $requestId = null): JsonResponse
    {
        return ApiResponse::validationError([
            'password' => [
                [
                    'code' => PasswordReusedException::PASSWORD_REUSED,
                    'message' => PasswordReusedException::MESSAGE,
                ],
            ],
        ], $requestId);
    }
}
