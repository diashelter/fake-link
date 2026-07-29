<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Infrastructure\Http\Requests\RequestPasswordResetRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\RequestPasswordReset;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class RequestPasswordResetController
{
    public function __construct(
        private RequestPasswordReset $requestPasswordReset,
        private AuthResponseFactory $authResponseFactory,
    ) {}

    public function __invoke(RequestPasswordResetRequest $request): Response
    {
        try {
            $this->requestPasswordReset->execute($request->toDto());
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
