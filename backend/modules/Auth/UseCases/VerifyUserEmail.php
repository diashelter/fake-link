<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\Repositories\EmailActionTokenRepository;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\DTOs\Input\VerifyUserEmailDto;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Exceptions\InvalidVerificationTokenException;

final class VerifyUserEmail
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EmailActionTokenRepository $emailActionTokenRepository,
        private readonly TokenHasher $tokenHasher,
        private readonly RevokeAuthToken $revokeAuthToken,
    ) {}

    public function execute(VerifyUserEmailDto $input): void
    {
        $user = $this->userRepository->findById($input->principal->userId());

        if ($user === null) {
            throw InvalidVerificationTokenException::invalid();
        }

        if ($user->status() === UserStatus::Active) {
            throw EmailAlreadyVerifiedException::alreadyVerified();
        }

        $now = Carbon::now()->toDateTimeImmutable();
        $tokenHash = $this->tokenHasher->hash($input->plainTextEmailToken);

        DB::transaction(function () use ($input, $user, $tokenHash, $now): void {
            $consumed = $this->emailActionTokenRepository->consumeForUser(
                $tokenHash,
                $input->principal->userId(),
                EmailActionPurpose::EmailVerification,
                $now,
            );

            if (! $consumed) {
                throw InvalidVerificationTokenException::invalid();
            }

            $this->userRepository->update($user->markEmailVerified($now));
            $this->revokeAuthToken->byId($input->principal->tokenId());
        });
    }
}
