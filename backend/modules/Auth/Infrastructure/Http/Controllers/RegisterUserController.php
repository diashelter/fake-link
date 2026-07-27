<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Auth\Exceptions\InviteAllowlistUnavailableException;
use Modules\Auth\Exceptions\RegistrationNotAllowedException;
use Modules\Auth\Infrastructure\Http\Requests\RegisterUserRequest;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Modules\Auth\Infrastructure\Http\Responses\AuthResponseFactory;
use Modules\Auth\UseCases\RegisterUser;

final readonly class RegisterUserController
{
    public function __construct(
        private RegisterUser $registerUser,
        private AuthResponseFactory $authResponseFactory,
        private AuthErrorResponseFactory $authErrorResponseFactory,
    ) {}

    public function __invoke(RegisterUserRequest $request): JsonResponse
    {
        try {
            $registered = $this->registerUser->execute($request->toDto());
        } catch (RegistrationNotAllowedException) {
            return $this->authErrorResponseFactory->registrationNotAllowed();
        } catch (InviteAllowlistUnavailableException) {
            return $this->authErrorResponseFactory->serviceUnavailable();
        }

        return $this->authResponseFactory->issued($registered);
    }
}
