<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Input\LoginUserDto;
use Modules\Auth\DTOs\Output\LoggedInUserDto;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Exceptions\InvalidCredentialsException;

final class LoginUser
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly IssueAuthToken $issueAuthToken,
    ) {}

    public function execute(LoginUserDto $input): LoggedInUserDto
    {
        $email = EmailAddress::fromString($input->email);
        $user = $this->userRepository->findByEmail($email);

        if ($user === null) {
            $this->passwordHasher->verify(
                $input->plainTextPassword,
                (string) config('auth.dummy_password_hash'),
            );

            throw InvalidCredentialsException::invalid();
        }

        if (! $this->passwordHasher->verify($input->plainTextPassword, $user->passwordHash())) {
            throw InvalidCredentialsException::invalid();
        }

        $tokenKind = match ($user->status()) {
            UserStatus::Suspended => throw AuthTokenException::accountSuspended(),
            UserStatus::DeletionPending => throw AuthTokenException::accountPendingDeletion(),
            UserStatus::PendingVerification => TokenKind::Verification,
            UserStatus::Active => TokenKind::Session,
        };

        $token = $this->issueAuthToken->execute(
            new IssueAuthTokenDto($user->id(), $tokenKind),
        );

        return new LoggedInUserDto($user, $token);
    }
}
