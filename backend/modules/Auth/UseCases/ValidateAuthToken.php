<?php

declare(strict_types=1);

namespace Modules\Auth\UseCases;

use Illuminate\Support\Carbon;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;

final class ValidateAuthToken
{
    private const LAST_USED_THROTTLE_SECONDS = 900;

    public function __construct(
        private readonly AuthTokenRepository $authTokenRepository,
        private readonly UserRepository $userRepository,
        private readonly TokenHasher $tokenHasher,
    ) {}

    public function execute(string $plainText): AuthenticatedPrincipal
    {
        $tokenHash = $this->tokenHasher->hash($plainText);
        $token = $this->authTokenRepository->findByHash($tokenHash);

        if ($token === null) {
            throw AuthTokenException::unauthenticated();
        }

        $now = Carbon::now();

        if ($token->isExpiredAt($now) || $token->isIdleExpiredAt($now)) {
            throw AuthTokenException::unauthenticated();
        }

        $user = $this->userRepository->findById($token->userId());

        if ($user === null) {
            throw AuthTokenException::unauthenticated();
        }

        if ($user->status() === UserStatus::Suspended) {
            throw AuthTokenException::accountSuspended();
        }

        if ($user->status() === UserStatus::DeletionPending) {
            throw AuthTokenException::accountPendingDeletion();
        }

        $this->authTokenRepository->touchLastUsedAtIfStale(
            $token->id(),
            $now->toDateTimeImmutable(),
            self::LAST_USED_THROTTLE_SECONDS,
        );

        return new AuthenticatedPrincipalRecord(
            userId: $user->id(),
            userStatus: $user->status(),
            tokenKind: $token->tokenKind(),
            tokenId: $token->id(),
            expiresAt: $token->expiresAt(),
        );
    }
}
