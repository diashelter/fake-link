<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\InviteAllowlist;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\PasswordPolicy;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Input\RegisterUserDto;
use Modules\Auth\DTOs\Output\RegisteredUserDto;
use Modules\Auth\Exceptions\AuthDomainException;
use Modules\Auth\Exceptions\RegistrationNotAllowedException;
use Throwable;

final class RegisterUser
{
    public function __construct(
        private readonly InviteAllowlist $inviteAllowlist,
        private readonly UserRepository $userRepository,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly PasswordHasher $passwordHasher,
        private readonly IssueAuthToken $issueAuthToken,
        private readonly QueueEmailVerification $queueEmailVerification,
    ) {}

    public function execute(RegisterUserDto $input): RegisteredUserDto
    {
        $email = EmailAddress::fromString($input->email);

        if (! $this->inviteAllowlist->isInvited($email)) {
            throw RegistrationNotAllowedException::notAllowed();
        }

        if ($this->userRepository->existsByEmail($email)) {
            throw RegistrationNotAllowedException::notAllowed();
        }

        $this->passwordPolicy->validate($input->plainTextPassword);

        try {
            $registered = DB::transaction(function () use ($input, $email): RegisteredUserDto {
                $userId = $this->userRepository->nextIdentity();
                $now = Carbon::now()->toDateTimeImmutable();

                $user = User::create(
                    id: $userId,
                    name: $input->name,
                    email: $email,
                    passwordHash: $this->passwordHasher->hash($input->plainTextPassword),
                    status: UserStatus::PendingVerification,
                    emailVerifiedAt: null,
                    termsVersion: (string) config('auth.terms.current_version'),
                    termsAcceptedAt: $now,
                );

                try {
                    $this->userRepository->save($user);
                } catch (AuthDomainException $exception) {
                    if ($exception->errorCode() === AuthDomainException::EMAIL_ALREADY_IN_USE) {
                        throw RegistrationNotAllowedException::notAllowed();
                    }

                    throw $exception;
                }

                $token = $this->issueAuthToken->execute(
                    new IssueAuthTokenDto($userId, TokenKind::Verification),
                );

                return new RegisteredUserDto($user, $token);
            });
        } catch (RegistrationNotAllowedException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RegistrationNotAllowedException::notAllowed();
        }

        try {
            $this->queueEmailVerification->dispatch($registered->user->id());
        } catch (Throwable) {
            // Best-effort: registration and token already committed.
        }

        return $registered;
    }
}
