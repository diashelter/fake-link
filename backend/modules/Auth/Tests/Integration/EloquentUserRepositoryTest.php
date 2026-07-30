<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\AuthDomainException;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeAuthUserRepository(): EloquentUserRepository
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new EloquentUserRepository(
        userIdGenerator: new Uuid7UserIdGenerator,
        userMapper: new UserMapper,
    );
}

function makePersistableUser(
    EloquentUserRepository $repository,
    string $email = 'user@example.com',
    UserStatus $status = UserStatus::PendingVerification,
): User {
    return User::create(
        id: $repository->nextIdentity(),
        name: 'Jane Doe',
        email: EmailAddress::fromString($email),
        passwordHash: '$argon2id$v=19$m=65536,t=4,p=1$fixture',
        status: $status,
        emailVerifiedAt: null,
        termsVersion: '2026-01',
        termsAcceptedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    );
}

describe('EloquentUserRepository', function () {
    it('persists a user with a generated uuid v7 identifier', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'persisted@example.com');

        $repository->save($user);

        $model = UserModel::query()->find($user->id()->value());

        expect($model)->not->toBeNull()
            ->and($model->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });

    it('stores normalized email addresses', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, '  Foo@Bar.com  ');

        $repository->save($user);

        expect(UserModel::query()->find($user->id()->value())?->email)->toBe('foo@bar.com');
    });

    it('rejects duplicate normalized emails', function () {
        $repository = makeAuthUserRepository();
        $repository->save(makePersistableUser($repository, 'duplicate@example.com'));

        expect(fn () => $repository->save(makePersistableUser($repository, '  DUPLICATE@example.com  ')))
            ->toThrow(AuthDomainException::class, 'The email address is already in use.');
    });

    it('returns uuid v7 identities from nextIdentity', function () {
        $identity = makeAuthUserRepository()->nextIdentity();

        expect($identity->value())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });

    it('persists explicit status and terms fields', function () {
        $repository = makeAuthUserRepository();
        $user = User::create(
            id: $repository->nextIdentity(),
            name: 'Jane Doe',
            email: EmailAddress::fromString('terms@example.com'),
            passwordHash: 'hash',
            status: UserStatus::Active,
            emailVerifiedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            termsVersion: '2026-02',
            termsAcceptedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );

        $repository->save($user);

        $model = UserModel::query()->find($user->id()->value());

        expect($model?->status)->toBe('active')
            ->and($model?->terms_version)->toBe('2026-02')
            ->and($model?->terms_accepted_at?->toIso8601String())->toBe('2026-02-01T00:00:00+00:00');
    });

    it('checks email existence using normalized values', function () {
        $repository = makeAuthUserRepository();
        $repository->save(makePersistableUser($repository, 'exists@example.com'));

        expect($repository->existsByEmail(EmailAddress::fromString('EXISTS@example.com')))->toBeTrue()
            ->and($repository->existsByEmail(EmailAddress::fromString('missing@example.com')))->toBeFalse();
    });

    it('finds a user by id and returns null when missing', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'find-by-id@example.com');

        $repository->save($user);

        $found = $repository->findById($user->id());

        expect($found)->not->toBeNull()
            ->and($found->id()->value())->toBe($user->id()->value())
            ->and($repository->findById(
                UserId::fromString('018e8b8a-7b6a-7000-8000-999999999999')
            ))->toBeNull();
    });

    it('finds a user by email and returns null when missing', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'find-by-email@example.com');

        $repository->save($user);

        $found = $repository->findByEmail(EmailAddress::fromString('find-by-email@example.com'));

        expect($found)->not->toBeNull()
            ->and($found->id()->value())->toBe($user->id()->value())
            ->and($found->email()->value())->toBe('find-by-email@example.com')
            ->and($repository->findByEmail(EmailAddress::fromString('missing@example.com')))->toBeNull();
    });

    it('finds a user by email using normalized case-insensitive match', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'Case.User@Example.COM');

        $repository->save($user);

        $found = $repository->findByEmail(EmailAddress::fromString('  CASE.USER@example.com  '));

        expect($found)->not->toBeNull()
            ->and($found->id()->value())->toBe($user->id()->value())
            ->and($found->email()->value())->toBe('case.user@example.com');
    });

    it('updates status to active and persists email_verified_at', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'verify-update@example.com');
        $repository->save($user);

        $verifiedAt = new DateTimeImmutable('2026-03-01T15:30:00+00:00');
        $verified = $user->markEmailVerified($verifiedAt);

        $repository->update($verified);

        $model = UserModel::query()->find($user->id()->value());
        $reloaded = $repository->findById($user->id());

        expect($model?->status)->toBe('active')
            ->and($model?->email_verified_at?->toIso8601String())->toBe('2026-03-01T15:30:00+00:00')
            ->and($model?->name)->toBe('Jane Doe')
            ->and($model?->email)->toBe('verify-update@example.com')
            ->and($reloaded)->not->toBeNull()
            ->and($reloaded->status())->toBe(UserStatus::Active)
            ->and($reloaded->emailVerifiedAt()?->format('Y-m-d\TH:i:sP'))->toBe('2026-03-01T15:30:00+00:00');
    });

    it('loads a profile with real created_at and updated_at timestamps', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'profile@example.com');
        $repository->save($user);

        $model = UserModel::query()->find($user->id()->value());
        $profile = $repository->findProfileById($user->id());

        expect($profile)->not->toBeNull()
            ->and($profile->user->id()->value())->toBe($user->id()->value())
            ->and($profile->user->name())->toBe('Jane Doe')
            ->and($profile->createdAt->format('Y-m-d\TH:i:sP'))->toBe(
                DateTimeImmutable::createFromInterface($model->created_at)->format('Y-m-d\TH:i:sP')
            )
            ->and($profile->updatedAt->format('Y-m-d\TH:i:sP'))->toBe(
                DateTimeImmutable::createFromInterface($model->updated_at)->format('Y-m-d\TH:i:sP')
            )
            ->and($repository->findProfileById(
                UserId::fromString('018e8b8a-7b6a-7000-8000-999999999999')
            ))->toBeNull();
    });

    it('persists renamed name and bumps updated_at when timestamp is provided', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'rename@example.com');
        $repository->save($user);

        $renamedAt = new DateTimeImmutable('2030-04-01T12:00:00+00:00');
        $repository->update($user->withName('Ana Silva'), $renamedAt);

        $after = UserModel::query()->find($user->id()->value());
        $profile = $repository->findProfileById($user->id());

        expect($after?->name)->toBe('Ana Silva')
            ->and($after?->updated_at?->toIso8601String())->toBe('2030-04-01T12:00:00+00:00')
            ->and($profile?->user->name())->toBe('Ana Silva')
            ->and($profile?->updatedAt->format('Y-m-d\TH:i:sP'))->toBe('2030-04-01T12:00:00+00:00');
    });

    it('update without updatedAt still persists domain fields without requiring an explicit timestamp', function () {
        $repository = makeAuthUserRepository();
        $user = makePersistableUser($repository, 'no-bump@example.com');
        $repository->save($user);

        $repository->update($user->markEmailVerified(new DateTimeImmutable('2026-03-15T10:00:00+00:00')));

        $after = UserModel::query()->find($user->id()->value());
        $reloaded = $repository->findById($user->id());

        expect($after?->status)->toBe('active')
            ->and($after?->name)->toBe('Jane Doe')
            ->and($after?->email_verified_at?->toIso8601String())->toBe('2026-03-15T10:00:00+00:00')
            ->and($reloaded?->status())->toBe(UserStatus::Active)
            ->and($reloaded?->name())->toBe('Jane Doe');
    });
});
