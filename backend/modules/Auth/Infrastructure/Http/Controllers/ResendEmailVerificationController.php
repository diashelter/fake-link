<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Exceptions\ResourceNotFoundException;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\ResendEmailVerification;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ResendEmailVerificationController
{
    public function __construct(
        private Application $app,
        private ResendEmailVerification $resendEmailVerification,
        private AuthResponseFactory $authResponseFactory,
        private AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    public function __invoke(): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $this->resendEmailVerification->execute($principal);
        } catch (EmailAlreadyVerifiedException) {
            return $this->authErrorResponseFactory->emailAlreadyVerified();
        } catch (ResourceNotFoundException) {
            return $this->authErrorResponseFactory->resourceNotFound();
        } catch (Throwable) {
            return $this->internalError();
        }

        return $this->authResponseFactory->accepted();
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
