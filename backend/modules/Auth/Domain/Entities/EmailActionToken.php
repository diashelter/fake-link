<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Entities;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

final class EmailActionToken
{
    private function __construct(
        private readonly EmailActionTokenId $id,
        private readonly UserId $userId,
        private readonly EmailActionPurpose $purpose,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $usedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    public static function issue(
        EmailActionTokenId $id,
        UserId $userId,
        EmailActionPurpose $purpose,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            purpose: $purpose,
            expiresAt: $expiresAt,
            usedAt: null,
            createdAt: $createdAt,
        );
    }

    public static function reconstitute(
        EmailActionTokenId $id,
        UserId $userId,
        EmailActionPurpose $purpose,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $usedAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            userId: $userId,
            purpose: $purpose,
            expiresAt: $expiresAt,
            usedAt: $usedAt,
            createdAt: $createdAt,
        );
    }

    public function id(): EmailActionTokenId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function purpose(): EmailActionPurpose
    {
        return $this->purpose;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpiredAt(CarbonInterface $now): bool
    {
        return $now->greaterThanOrEqualTo($this->expiresAt);
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }
}
