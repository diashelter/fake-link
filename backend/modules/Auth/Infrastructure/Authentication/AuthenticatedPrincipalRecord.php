<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Authentication;

use DateTimeImmutable;
use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

final readonly class AuthenticatedPrincipalRecord implements AuthenticatedPrincipal
{
    public function __construct(
        private UserId $userId,
        private UserStatus $userStatus,
        private TokenKind $tokenKind,
        private AuthTokenId $tokenId,
        private DateTimeImmutable $expiresAt,
    ) {}

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function userStatus(): UserStatus
    {
        return $this->userStatus;
    }

    public function tokenKind(): TokenKind
    {
        return $this->tokenKind;
    }

    public function tokenId(): AuthTokenId
    {
        return $this->tokenId;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
