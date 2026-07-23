<?php

declare(strict_types=1);

use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;

describe('User entity', function () {
    it('creates a user with all required fields via factory', function () {
        $acceptedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $user = User::create(
            id: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            name: 'Jane Doe',
            email: EmailAddress::fromString('jane@example.com'),
            passwordHash: '$argon2id$v=19$m=65536,t=4,p=1$hash',
            status: UserStatus::PendingVerification,
            emailVerifiedAt: null,
            termsVersion: '2026-01',
            termsAcceptedAt: $acceptedAt,
        );

        expect($user->id()->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc')
            ->and($user->name())->toBe('Jane Doe')
            ->and($user->email()->value())->toBe('jane@example.com')
            ->and($user->passwordHash())->toBe('$argon2id$v=19$m=65536,t=4,p=1$hash')
            ->and($user->status())->toBe(UserStatus::PendingVerification)
            ->and($user->emailVerifiedAt())->toBeNull()
            ->and($user->termsVersion())->toBe('2026-01')
            ->and($user->termsAcceptedAt())->toEqual($acceptedAt);
    });

    it('exposes normalized email without a mutator', function () {
        $user = User::create(
            id: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            name: 'Jane Doe',
            email: EmailAddress::fromString('  Jane@Example.com  '),
            passwordHash: 'hash',
            status: UserStatus::Active,
            emailVerifiedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            termsVersion: '2026-01',
            termsAcceptedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        expect($user->email()->value())->toBe('jane@example.com');
    });
});
