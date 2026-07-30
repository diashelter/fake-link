<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Infrastructure\Http\Requests\LogoutAllRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\LogoutAllSessions;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LogoutAllController
{
    public function __construct(
        private Application $app,
        private LogoutAllSessions $logoutAllSessions,
        private AuthResponseFactory $authResponseFactory,
        private AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    public function __invoke(LogoutAllRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $this->logoutAllSessions->execute($principal, $request->toDto());
        } catch (InvalidCredentialsException) {
            return $this->authErrorResponseFactory->invalidCredentials();
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
