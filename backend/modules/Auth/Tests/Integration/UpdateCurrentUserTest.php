<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\UpdateCurrentUserDto;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\UpdateCurrentUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeUpdateCurrentUserUseCase(): UpdateCurrentUser
{
    return new UpdateCurrentUser(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
    );
}

describe('UpdateCurrentUser', function () {
    it('persists a renamed name and advances updated_at', function () {
        Illuminate\Support\Carbon::setTestNow('2026-07-30 12:00:00');

        $user = UserModel::factory()->active()->create([
            'name' => 'Old Name',
            'email' => 'rename.me@example.com',
        ]);
        $before = $user->fresh();

        Illuminate\Support\Carbon::setTestNow('2026-07-30 12:00:05');

        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString($user->id),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef0123456e'),
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );

        $profile = makeUpdateCurrentUserUseCase()->execute(
            $principal,
            new UpdateCurrentUserDto(name: 'Ana Silva'),
        );

        $after = UserModel::query()->find($user->id);

        expect($profile->user->name())->toBe('Ana Silva')
            ->and($after?->name)->toBe('Ana Silva')
            ->and($after?->email)->toBe('rename.me@example.com')
            ->and($after?->updated_at->gt($before->updated_at))->toBeTrue()
            ->and($profile->updatedAt->format('Y-m-d\TH:i:sP'))->toBe(
                DateTimeImmutable::createFromInterface($after->updated_at)->format('Y-m-d\TH:i:sP')
            );

        Illuminate\Support\Carbon::setTestNow();
    });

    it('returns the same profile without writing when the name is unchanged', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Same Name',
            'email' => 'noop.me@example.com',
        ]);
        $before = $user->fresh();
        $updatedAtBefore = $before->updated_at->toIso8601String();

        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString($user->id),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef0123456f'),
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );

        $profile = makeUpdateCurrentUserUseCase()->execute(
            $principal,
            new UpdateCurrentUserDto(name: 'Same Name'),
        );

        $after = UserModel::query()->find($user->id);

        expect($profile->user->name())->toBe('Same Name')
            ->and($after?->name)->toBe('Same Name')
            ->and($after?->updated_at?->toIso8601String())->toBe($updatedAtBefore)
            ->and($profile->updatedAt->format('Y-m-d\TH:i:sP'))->toBe(
                DateTimeImmutable::createFromInterface($before->updated_at)->format('Y-m-d\TH:i:sP')
            );
    });

    it('never changes the email when renaming', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Before',
            'email' => 'immutable@example.com',
        ]);

        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString($user->id),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234570'),
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );

        $profile = makeUpdateCurrentUserUseCase()->execute(
            $principal,
            new UpdateCurrentUserDto(name: 'After'),
        );

        $after = UserModel::query()->find($user->id);

        expect($profile->user->email()->value())->toBe('immutable@example.com')
            ->and($after?->email)->toBe('immutable@example.com')
            ->and($after?->name)->toBe('After');
    });
});
