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

    it('markEmailVerified returns active status with emailVerifiedAt and leaves other fields unchanged', function () {
        $acceptedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $verifiedAt = new DateTimeImmutable('2026-01-02T12:00:00+00:00');
        $original = User::create(
            id: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            name: 'Jane Doe',
            email: EmailAddress::fromString('jane@example.com'),
            passwordHash: '$argon2id$v=19$m=65536,t=4,p=1$hash',
            status: UserStatus::PendingVerification,
            emailVerifiedAt: null,
            termsVersion: '2026-01',
            termsAcceptedAt: $acceptedAt,
        );

        $verified = $original->markEmailVerified($verifiedAt);

        expect($verified)->not->toBe($original)
            ->and($verified->status())->toBe(UserStatus::Active)
            ->and($verified->emailVerifiedAt())->toEqual($verifiedAt)
            ->and($verified->id()->value())->toBe($original->id()->value())
            ->and($verified->name())->toBe('Jane Doe')
            ->and($verified->email()->value())->toBe('jane@example.com')
            ->and($verified->passwordHash())->toBe('$argon2id$v=19$m=65536,t=4,p=1$hash')
            ->and($verified->termsVersion())->toBe('2026-01')
            ->and($verified->termsAcceptedAt())->toEqual($acceptedAt)
            ->and($original->status())->toBe(UserStatus::PendingVerification)
            ->and($original->emailVerifiedAt())->toBeNull();
    });

    it('withPasswordHash returns a new instance with updated hash leaving status and profile unchanged', function () {
        $acceptedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $verifiedAt = new DateTimeImmutable('2026-01-02T12:00:00+00:00');
        $original = User::create(
            id: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            name: 'Jane Doe',
            email: EmailAddress::fromString('jane@example.com'),
            passwordHash: '$argon2id$v=19$m=65536,t=4,p=1$old-hash',
            status: UserStatus::Active,
            emailVerifiedAt: $verifiedAt,
            termsVersion: '2026-01',
            termsAcceptedAt: $acceptedAt,
        );

        $updated = $original->withPasswordHash('$argon2id$v=19$m=65536,t=4,p=1$new-hash');

        expect($updated)->not->toBe($original)
            ->and($updated->passwordHash())->toBe('$argon2id$v=19$m=65536,t=4,p=1$new-hash')
            ->and($updated->status())->toBe(UserStatus::Active)
            ->and($updated->emailVerifiedAt())->toEqual($verifiedAt)
            ->and($updated->id()->value())->toBe($original->id()->value())
            ->and($updated->name())->toBe('Jane Doe')
            ->and($updated->email()->value())->toBe('jane@example.com')
            ->and($updated->termsVersion())->toBe('2026-01')
            ->and($updated->termsAcceptedAt())->toEqual($acceptedAt)
            ->and($original->passwordHash())->toBe('$argon2id$v=19$m=65536,t=4,p=1$old-hash');
    });
});
