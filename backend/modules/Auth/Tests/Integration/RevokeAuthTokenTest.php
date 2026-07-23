<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\RevokeAllUserTokens;
use Modules\Auth\UseCases\RevokeAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRevokeAuthTokenUseCase(): RevokeAuthToken
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new RevokeAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
    );
}

function makeRevokeAllUserTokensUseCase(): RevokeAllUserTokens
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new RevokeAllUserTokens(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
    );
}

describe('RevokeAuthToken', function () {
    it('removes an existing token and is idempotent when missing', function () {
        $user = UserModel::factory()->create();
        $issued = (new IssueAuthToken(
            authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
            authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        ))->execute(new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session));

        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash($issued->plainTextToken);
        $revoke = makeRevokeAuthTokenUseCase();

        $revoke->byHash($hash);

        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder count)
        expect(AuthTokenModel::query()->count())->toBe(0);

        $revoke->byHash($hash);
        $revoke->byId($issued->tokenId);
        $revoke->byId(AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-999999999999'));
    });
});

describe('RevokeAllUserTokens', function () {
    it('removes all tokens for a user and returns zero when none exist', function () {
        $user = UserModel::factory()->create();
        $issue = new IssueAuthToken(
            authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
            authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        );
        $dto = new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session);

        $issue->execute($dto);
        $issue->execute($dto);

        $deleted = makeRevokeAllUserTokensUseCase()->execute(UserId::fromString($user->id));

        expect($deleted)->toBe(2)
            // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder count)
            ->and(AuthTokenModel::query()->count())->toBe(0)
            ->and(makeRevokeAllUserTokensUseCase()->execute(UserId::fromString($user->id)))->toBe(0);
    });
});
