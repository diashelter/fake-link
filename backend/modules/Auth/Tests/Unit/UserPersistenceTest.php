<?php

declare(strict_types=1);

use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Tests\TestCase;

uses(TestCase::class);

describe('UserMapper', function () {
    it('maps persistence attributes to the domain entity', function () {
        $model = (new UserModel)->forceFill([
            'id' => '018e8b8a-7b6a-7000-8000-123456789abc',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'hash-value',
            'status' => 'active',
            'email_verified_at' => '2026-02-01T00:00:00+00:00',
            'terms_version' => '2026-01',
            'terms_accepted_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $user = (new UserMapper)->toDomain($model);

        expect($user->id()->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc')
            ->and($user->email()->value())->toBe('jane@example.com')
            ->and($user->status())->toBe(UserStatus::Active);
    });

    it('maps a domain entity to persistence attributes', function () {
        $user = User::create(
            id: UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
            name: 'Jane Doe',
            email: EmailAddress::fromString('jane@example.com'),
            passwordHash: 'hash-value',
            status: UserStatus::PendingVerification,
            emailVerifiedAt: null,
            termsVersion: '2026-01',
            termsAcceptedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $attributes = (new UserMapper)->toPersistence($user);

        expect($attributes['email'])->toBe('jane@example.com')
            ->and($attributes['status'])->toBe('pending_verification')
            ->and($attributes['password'])->toBe('hash-value');
    });
});

describe('Uuid7UserIdGenerator', function () {
    it('returns a valid uuid v7 value object', function () {
        $userId = (new Uuid7UserIdGenerator)->generate();

        expect($userId->value())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });
});
