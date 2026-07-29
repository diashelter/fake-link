<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\QueuePasswordReset;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\DTOs\Input\RequestPasswordResetDto;

final class RequestPasswordReset
{
    private const TIMING_DUMMY_PLAINTEXT = 'auth-password-reset-timing';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordHasher $passwordHasher,
        private readonly QueuePasswordReset $queuePasswordReset,
    ) {}

    public function execute(RequestPasswordResetDto $input): void
    {
        $email = EmailAddress::fromString($input->email);
        $user = $this->userRepository->findByEmail($email);

        $this->passwordHasher->verify(
            self::TIMING_DUMMY_PLAINTEXT,
            (string) config('auth.dummy_password_hash'),
        );

        if ($user === null || $user->status() !== UserStatus::Active) {
            return;
        }

        $this->queuePasswordReset->dispatch($user->id());
    }
}
