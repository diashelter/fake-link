<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\LogoutCurrentToken;
use Modules\Auth\UseCases\RevokeAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeLogoutCurrentTokenUseCase(): LogoutCurrentToken
{
    return new LogoutCurrentToken(
        revokeAuthToken: new RevokeAuthToken(
            authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        ),
    );
}

function makeIssueAuthTokenForLogout(): IssueAuthToken
{
    return new IssueAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

describe('LogoutCurrentToken', function () {
    it('revokes only the principal token and leaves other tokens intact', function () {
        $user = UserModel::factory()->active()->create();
        $userId = UserId::fromString($user->id);
        $issue = makeIssueAuthTokenForLogout();

        $tokenA = $issue->execute(new IssueAuthTokenDto($userId, TokenKind::Session));
        $tokenB = $issue->execute(new IssueAuthTokenDto($userId, TokenKind::Session));

        $principal = new AuthenticatedPrincipalRecord(
            userId: $userId,
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: $tokenA->tokenId,
            expiresAt: $tokenA->expiresAt,
        );

        makeLogoutCurrentTokenUseCase()->execute($principal);

        expect(AuthTokenModel::query()->find($tokenA->tokenId->value()))->toBeNull()
            ->and(AuthTokenModel::query()->find($tokenB->tokenId->value()))->not->toBeNull()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('revokes a verification token without affecting a session token of the same user', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::PendingVerification->value]);
        $userId = UserId::fromString($user->id);
        $issue = makeIssueAuthTokenForLogout();

        $verification = $issue->execute(new IssueAuthTokenDto($userId, TokenKind::Verification));
        $session = $issue->execute(new IssueAuthTokenDto($userId, TokenKind::Session));

        $principal = new AuthenticatedPrincipalRecord(
            userId: $userId,
            userStatus: UserStatus::PendingVerification,
            tokenKind: TokenKind::Verification,
            tokenId: $verification->tokenId,
            expiresAt: $verification->expiresAt,
        );

        makeLogoutCurrentTokenUseCase()->execute($principal);

        expect(AuthTokenModel::query()->find($verification->tokenId->value()))->toBeNull()
            ->and(AuthTokenModel::query()->find($session->tokenId->value()))->not->toBeNull();
    });
});
