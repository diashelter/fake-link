<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\Repositories\EmailActionTokenRepository;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\DTOs\Input\ResetPasswordDto;
use Modules\Auth\Exceptions\InvalidPasswordResetTokenException;
use Modules\Auth\Exceptions\PasswordReusedException;

final class ResetPassword
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailActionTokenRepository $emailActionTokenRepository,
        private readonly TokenHasher $tokenHasher,
        private readonly PasswordHasher $passwordHasher,
        private readonly RevokeAllUserTokens $revokeAllUserTokens,
    ) {}

    public function execute(ResetPasswordDto $input): void
    {
        $email = EmailAddress::fromString($input->email);
        $user = $this->userRepository->findByEmail($email);

        if ($user === null) {
            throw InvalidPasswordResetTokenException::invalid();
        }

        $now = Carbon::now()->toDateTimeImmutable();
        $tokenHash = $this->tokenHasher->hash($input->plainTextToken);
        $token = $this->emailActionTokenRepository->findByHash($tokenHash);

        if (
            $token === null
            || $token->userId()->value() !== $user->id()->value()
            || $token->purpose() !== EmailActionPurpose::PasswordReset
            || $token->isUsed()
            || $token->isExpiredAt(Carbon::instance($now))
        ) {
            throw InvalidPasswordResetTokenException::invalid();
        }

        if ($this->passwordHasher->verify($input->plainTextPassword, $user->passwordHash())) {
            throw PasswordReusedException::reused();
        }

        $newHash = $this->passwordHasher->hash($input->plainTextPassword);

        DB::transaction(function () use ($user, $tokenHash, $now, $newHash): void {
            $consumed = $this->emailActionTokenRepository->consumeForUser(
                $tokenHash,
                $user->id(),
                EmailActionPurpose::PasswordReset,
                $now,
            );

            if (! $consumed) {
                throw InvalidPasswordResetTokenException::invalid();
            }

            $this->userRepository->update($user->withPasswordHash($newHash));
            $this->revokeAllUserTokens->execute($user->id());
        });
    }
}
