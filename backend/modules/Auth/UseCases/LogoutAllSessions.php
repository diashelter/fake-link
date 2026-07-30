<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\DTOs\Input\LogoutAllSessionsDto;
use Modules\Auth\Exceptions\InvalidCredentialsException;

final class LogoutAllSessions
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly RevokeAllUserTokens $revokeAllUserTokens,
    ) {}

    public function execute(AuthenticatedPrincipal $principal, LogoutAllSessionsDto $input): void
    {
        $user = $this->userRepository->findById($principal->userId());

        if ($user === null) {
            throw InvalidCredentialsException::invalid();
        }

        if (! $this->passwordHasher->verify($input->currentPassword, $user->passwordHash())) {
            throw InvalidCredentialsException::invalid();
        }

        $this->revokeAllUserTokens->execute($user->id());
    }
}
