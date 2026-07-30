<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Infrastructure\Http\Requests\LogoutRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\LogoutCurrentToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LogoutController
{
    public function __construct(
        private Application $app,
        private LogoutCurrentToken $logoutCurrentToken,
        private AuthResponseFactory $authResponseFactory,
    ) {}

    public function __invoke(LogoutRequest $request): Response
    {
        $principal = $this->app->make(AuthenticatedPrincipal::class);

        try {
            $this->logoutCurrentToken->execute($principal);
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
