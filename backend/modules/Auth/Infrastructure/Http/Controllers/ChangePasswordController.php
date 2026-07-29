<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Exceptions\PasswordReusedException;
use Modules\Auth\Infrastructure\Http\Requests\ChangePasswordRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthValidationResponseFactory;
use Modules\Auth\UseCases\ChangePassword;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ChangePasswordController
{
    public function __construct(
        private Application $app,
        private ChangePassword $changePassword,
        private AuthResponseFactory $authResponseFactory,
        private AuthErrorResponseFactory $authErrorResponseFactory,
        private AuthValidationResponseFactory $authValidationResponseFactory,
    ) {}

    public function __invoke(ChangePasswordRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $this->changePassword->execute($principal, $request->toDto());
        } catch (InvalidCredentialsException) {
            return $this->authErrorResponseFactory->invalidCredentials();
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
