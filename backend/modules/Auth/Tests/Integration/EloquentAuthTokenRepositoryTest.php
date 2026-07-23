<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeAuthTokenRepository(): EloquentAuthTokenRepository
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new EloquentAuthTokenRepository(
        authTokenMapper: new AuthTokenMapper,
    );
}

function makePersistableAuthToken(
    UserId $userId,
    TokenKind $tokenKind = TokenKind::Session,
    ?AuthTokenId $id = null,
): AuthToken {
    $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
    $expiresAt = $createdAt->modify('+7 days');

    return AuthToken::issue(
        id: $id ?? AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc'),
        userId: $userId,
        tokenKind: $tokenKind,
        expiresAt: $expiresAt,
        createdAt: $createdAt,
    );
}

describe('EloquentAuthTokenRepository', function () {
    it('persists only the token hash and never plaintext', function () {
        $user = UserModel::factory()->create();
        $repository = makeAuthTokenRepository();
        $hasher = new Sha256TokenHasher;
        $plainText = 'test-plaintext-token-value';
        $token = makePersistableAuthToken(UserId::fromString($user->id));

        $repository->save($token, $hasher->hash($plainText));

        $model = AuthTokenModel::query()->find($token->id()->value());

        expect($model)->not->toBeNull()
            ->and($model->token_hash)->toBe($hasher->hash($plainText))
            ->and($model->token_hash)->not->toBe($plainText);
    });

    it('finds a token by hash and returns null when missing', function () {
        $user = UserModel::factory()->create();
        $repository = makeAuthTokenRepository();
        $hasher = new Sha256TokenHasher;
        $plainText = 'lookup-token-value';
        $hash = $hasher->hash($plainText);
        $token = makePersistableAuthToken(UserId::fromString($user->id));

        $repository->save($token, $hash);

        $found = $repository->findByHash($hash);

        expect($found)->not->toBeNull()
            ->and($found->id()->value())->toBe($token->id()->value())
            ->and($repository->findByHash($hasher->hash('missing-token')))->toBeNull();
    });

    it('deletes tokens by id and hash idempotently', function () {
        $user = UserModel::factory()->create();
        $repository = makeAuthTokenRepository();
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('revoke-me');
        $token = makePersistableAuthToken(UserId::fromString($user->id));

        $repository->save($token, $hash);
        $repository->deleteById($token->id());

        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder count)
        expect(AuthTokenModel::query()->count())->toBe(0);

        $repository->deleteById($token->id());
        $repository->deleteByHash($hash);
    });

    it('deletes all tokens for a user and returns the deleted count', function () {
        $user = UserModel::factory()->create();
        $otherUser = UserModel::factory()->create();
        $repository = makeAuthTokenRepository();
        $hasher = new Sha256TokenHasher;

        $repository->save(
            makePersistableAuthToken(UserId::fromString($user->id)),
            $hasher->hash('token-one'),
        );
        $repository->save(
            AuthToken::issue(
                id: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abd'),
                userId: UserId::fromString($user->id),
                tokenKind: TokenKind::Verification,
                expiresAt: new DateTimeImmutable('2026-01-08T00:00:00+00:00'),
                createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ),
            $hasher->hash('token-two'),
        );
        $repository->save(
            makePersistableAuthToken(
                UserId::fromString($otherUser->id),
                id: AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abe'),
            ),
            $hasher->hash('other-user-token'),
        );

        $deleted = $repository->deleteAllForUser(UserId::fromString($user->id));

        expect($deleted)->toBe(2)
            // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder count)
            ->and(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder count)
            ->and(AuthTokenModel::query()->where('user_id', $otherUser->id)->count())->toBe(1);
    });

    it('returns zero when deleting all tokens for a user with none', function () {
        $user = UserModel::factory()->create();
        $repository = makeAuthTokenRepository();

        expect($repository->deleteAllForUser(UserId::fromString($user->id)))->toBe(0);
    });

    it('touches last_used_at only when null or stale for at least 15 minutes', function () {
        Carbon::setTestNow('2026-01-01T12:00:00+00:00');

        $user = UserModel::factory()->create();
        $repository = makeAuthTokenRepository();
        $hasher = new Sha256TokenHasher;
        $token = makePersistableAuthToken(UserId::fromString($user->id));

        $repository->save($token, $hasher->hash('touch-token'));

        $now = new DateTimeImmutable('2026-01-01T12:00:00+00:00');

        expect($repository->touchLastUsedAtIfStale($token->id(), $now, 900))->toBeTrue()
            ->and(AuthTokenModel::query()->find($token->id()->value())?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T12:00:00+00:00');

        Carbon::setTestNow('2026-01-01T12:10:00+00:00');
        $recentNow = new DateTimeImmutable('2026-01-01T12:10:00+00:00');

        expect($repository->touchLastUsedAtIfStale($token->id(), $recentNow, 900))->toBeFalse()
            ->and(AuthTokenModel::query()->find($token->id()->value())?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T12:00:00+00:00');

        Carbon::setTestNow('2026-01-01T12:16:00+00:00');
        $staleNow = new DateTimeImmutable('2026-01-01T12:16:00+00:00');

        expect($repository->touchLastUsedAtIfStale($token->id(), $staleNow, 900))->toBeTrue()
            ->and(AuthTokenModel::query()->find($token->id()->value())?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T12:16:00+00:00');

        Carbon::setTestNow();
    });
});
