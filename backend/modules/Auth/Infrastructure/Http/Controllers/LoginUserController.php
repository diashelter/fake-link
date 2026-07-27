<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Infrastructure\Http\Requests\LoginUserRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\LoginUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LoginUserController
{
    public function __construct(
        private LoginUser $loginUser,
        private AuthResponseFactory $authResponseFactory,
        private AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    public function __invoke(LoginUserRequest $request): JsonResponse
    {
        try {
            $loggedIn = $this->loginUser->execute($request->toDto());
        } catch (InvalidCredentialsException) {
            return $this->authErrorResponseFactory->invalidCredentials();
        } catch (AuthTokenException $exception) {
            return match ($exception->errorCode()) {
                AuthTokenException::ACCOUNT_SUSPENDED => $this->authErrorResponseFactory->accountSuspended(),
                AuthTokenException::ACCOUNT_PENDING_DELETION => $this->authErrorResponseFactory->accountPendingDeletion(),
                default => $this->internalError(),
            };
        } catch (Throwable) {
            return $this->internalError();
        }

        return $this->authResponseFactory->authenticated($loggedIn);
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
