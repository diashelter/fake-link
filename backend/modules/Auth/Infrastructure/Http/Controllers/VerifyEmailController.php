<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Exceptions\InvalidVerificationTokenException;
use Modules\Auth\Infrastructure\Http\Requests\VerifyEmailRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\VerifyUserEmail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class VerifyEmailController
{
    public function __construct(
        private Application $app,
        private VerifyUserEmail $verifyUserEmail,
        private AuthResponseFactory $authResponseFactory,
        private AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    public function __invoke(VerifyEmailRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $this->verifyUserEmail->execute($request->toDto($principal));
        } catch (InvalidVerificationTokenException) {
            return $this->authErrorResponseFactory->invalidVerificationToken();
        } catch (EmailAlreadyVerifiedException) {
            return $this->authErrorResponseFactory->emailAlreadyVerified();
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
