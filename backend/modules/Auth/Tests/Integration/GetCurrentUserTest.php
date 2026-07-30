<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\GetCurrentUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeGetCurrentUserUseCase(): GetCurrentUser
{
    return new GetCurrentUser(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
    );
}

describe('GetCurrentUser', function () {
    it('returns a profile with real persistence timestamps', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Profile User',
            'email' => 'profile.me@example.com',
        ]);

        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString($user->id),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef0123456b'),
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );

        $profile = makeGetCurrentUserUseCase()->execute($principal);
        $fresh = $user->fresh();

        expect($profile->user->id()->value())->toBe($user->id)
            ->and($profile->user->name())->toBe('Profile User')
            ->and($profile->user->email()->value())->toBe('profile.me@example.com')
            ->and($profile->createdAt->format('Y-m-d\TH:i:sP'))->toBe(
                DateTimeImmutable::createFromInterface($fresh->created_at)->format('Y-m-d\TH:i:sP')
            )
            ->and($profile->updatedAt->format('Y-m-d\TH:i:sP'))->toBe(
                DateTimeImmutable::createFromInterface($fresh->updated_at)->format('Y-m-d\TH:i:sP')
            );
    });

    it('throws unauthenticated when the user is missing', function () {
        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString('01901234-5678-7abc-89ab-cdef0123456c'),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef0123456d'),
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );

        expect(fn () => makeGetCurrentUserUseCase()->execute($principal))
            ->toThrow(AuthTokenException::class, 'Authentication is required.');
    });
});
