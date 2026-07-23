<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Entities;

use DateTimeImmutable;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;

final class User
{
    private function __construct(
        private readonly UserId $id,
        private readonly string $name,
        private readonly EmailAddress $email,
        private readonly string $passwordHash,
        private readonly UserStatus $status,
        private readonly ?DateTimeImmutable $emailVerifiedAt,
        private readonly string $termsVersion,
        private readonly DateTimeImmutable $termsAcceptedAt,
    ) {}

    public static function create(
        UserId $id,
        string $name,
        EmailAddress $email,
        string $passwordHash,
        UserStatus $status,
        ?DateTimeImmutable $emailVerifiedAt,
        string $termsVersion,
        DateTimeImmutable $termsAcceptedAt,
    ): self {
        return new self(
            id: $id,
            name: $name,
            email: $email,
            passwordHash: $passwordHash,
            status: $status,
            emailVerifiedAt: $emailVerifiedAt,
            termsVersion: $termsVersion,
            termsAcceptedAt: $termsAcceptedAt,
        );
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function emailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function termsVersion(): string
    {
        return $this->termsVersion;
    }

    public function termsAcceptedAt(): DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }
}
