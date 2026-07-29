<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\DTOs\Input\ChangePasswordDto;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Exceptions\PasswordReusedException;

final class ChangePassword
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly RevokeAllUserTokens $revokeAllUserTokens,
    ) {}

    public function execute(AuthenticatedPrincipal $principal, ChangePasswordDto $input): void
    {
        $user = $this->userRepository->findById($principal->userId());

        if ($user === null) {
            throw InvalidCredentialsException::invalid();
        }

        if (! $this->passwordHasher->verify($input->currentPassword, $user->passwordHash())) {
            throw InvalidCredentialsException::invalid();
        }

        if ($this->passwordHasher->verify($input->plainTextPassword, $user->passwordHash())) {
            throw PasswordReusedException::reused();
        }

        $newHash = $this->passwordHasher->hash($input->plainTextPassword);

        DB::transaction(function () use ($user, $newHash): void {
            $this->userRepository->update($user->withPasswordHash($newHash));
            $this->revokeAllUserTokens->execute($user->id());
        });
    }
}
