<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Entities;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

final class AuthToken
{
    private function __construct(
        private readonly AuthTokenId $id,
        private readonly UserId $userId,
        private readonly TokenKind $tokenKind,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    public static function issue(
        AuthTokenId $id,
        UserId $userId,
        TokenKind $tokenKind,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            tokenKind: $tokenKind,
            expiresAt: $expiresAt,
            lastUsedAt: null,
            createdAt: $createdAt,
        );
    }

    public static function reconstitute(
        AuthTokenId $id,
        UserId $userId,
        TokenKind $tokenKind,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $lastUsedAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            tokenKind: $tokenKind,
            expiresAt: $expiresAt,
            lastUsedAt: $lastUsedAt,
            createdAt: $createdAt,
        );
    }

    public function id(): AuthTokenId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function tokenKind(): TokenKind
    {
        return $this->tokenKind;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpiredAt(CarbonInterface $now): bool
    {
        return $now->greaterThanOrEqualTo($this->expiresAt);
    }

    public function isIdleExpiredAt(CarbonInterface $now): bool
    {
        $reference = $this->lastUsedAt ?? $this->createdAt;
        $idleSeconds = $this->tokenKind->idleTtlSeconds();
        $elapsedSeconds = $now->getTimestamp() - $reference->getTimestamp();

        return $elapsedSeconds > $idleSeconds;
    }
}
