<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
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
});
