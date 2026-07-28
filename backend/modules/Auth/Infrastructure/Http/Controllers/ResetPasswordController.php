<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Exceptions\InvalidPasswordResetTokenException;
use Modules\Auth\Exceptions\PasswordReusedException;
use Modules\Auth\Infrastructure\Http\Requests\ResetPasswordRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthValidationResponseFactory;
use Modules\Auth\UseCases\ResetPassword;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ResetPasswordController
{
    public function __construct(
        private ResetPassword $resetPassword,
        private AuthResponseFactory $authResponseFactory,
        private AuthValidationResponseFactory $authValidationResponseFactory,
    ) {}

    public function __invoke(ResetPasswordRequest $request): Response
    {
        try {
            $this->resetPassword->execute($request->toDto());
        } catch (InvalidPasswordResetTokenException) {
            return $this->authValidationResponseFactory->invalidPasswordResetToken();
        } catch (PasswordReusedException) {
            return $this->authValidationResponseFactory->passwordReused();
        } catch (Throwable) {
            return $this->internalError();
        }

        return $this->authResponseFactory->noContent();
    }

    private function internalError(): JsonResponse
    {
        return response()->json([
            'code' => 'INTERNAL_ERROR',
            'message' => 'An unexpected error occurred.',
            'request_id' => 'stub-request-id',
        ], Response::HTTP_INTERNAL_SERVER_ERROR)->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
