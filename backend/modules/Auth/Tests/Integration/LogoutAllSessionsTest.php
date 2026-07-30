<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Input\LogoutAllSessionsDto;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\LogoutAllSessions;
use Modules\Auth\UseCases\RevokeAllUserTokens;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeLogoutAllSessionsUseCase(): LogoutAllSessions
{
    return new LogoutAllSessions(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        passwordHasher: new LaravelPasswordHasher,
        revokeAllUserTokens: new RevokeAllUserTokens(
            new EloquentAuthTokenRepository(new AuthTokenMapper),
        ),
    );
}

function makeIssueAuthTokenForLogoutAll(): IssueAuthToken
{
    return new IssueAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

/**
 * @return array{user: UserModel, principal: AuthenticatedPrincipalRecord, currentPassword: string}
 */
function activeUserWithDualSessionTokens(string $currentPassword = 'CurrentPass1!xx'): array
{
    $hasher = new LaravelPasswordHasher;
    $user = UserModel::factory()->active()->create([
        'email' => 'logout.all@example.com',
        'password' => $hasher->hash($currentPassword),
        'name' => 'Logout All User',
    ]);
    $userId = UserId::fromString($user->id);

    $issue = makeIssueAuthTokenForLogoutAll();
    $tokenA = $issue->execute(new IssueAuthTokenDto($userId, TokenKind::Session));
    $issue->execute(new IssueAuthTokenDto($userId, TokenKind::Session));

    $principal = new AuthenticatedPrincipalRecord(
        userId: $userId,
        userStatus: UserStatus::Active,
        tokenKind: TokenKind::Session,
        tokenId: $tokenA->tokenId,
        expiresAt: $tokenA->expiresAt,
    );

    return [
        'user' => $user->fresh(),
        'principal' => $principal,
        'currentPassword' => $currentPassword,
    ];
}

describe('LogoutAllSessions', function () {
    it('revokes all tokens when current password matches without changing user fields', function () {
        $fixture = activeUserWithDualSessionTokens();
        $hasher = new LaravelPasswordHasher;
        $before = UserModel::query()->find($fixture['user']->id);

        makeLogoutAllSessionsUseCase()->execute(
            $fixture['principal'],
            new LogoutAllSessionsDto(currentPassword: $fixture['currentPassword']),
        );

        $after = UserModel::query()->find($fixture['user']->id);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe(0)
            ->and($after?->status)->toBe($before?->status)
            ->and($after?->name)->toBe($before?->name)
            ->and($after?->email)->toBe($before?->email)
            ->and($after?->password)->toBe($before?->password)
            ->and($hasher->verify($fixture['currentPassword'], $after->password))->toBeTrue();
    });

    it('rejects wrong password without revoking tokens and without leaking plaintext', function () {
        $fixture = activeUserWithDualSessionTokens();
        $sentinel = 'SENTINEL_WRONG_PASSWORD_LOGOUT_ALL_xyz';

        try {
            makeLogoutAllSessionsUseCase()->execute(
                $fixture['principal'],
                new LogoutAllSessionsDto(currentPassword: $sentinel),
            );
            expect(false)->toBeTrue('Expected InvalidCredentialsException');
        } catch (InvalidCredentialsException $exception) {
            expect($exception->getMessage())->not->toContain($sentinel)
                // @phpstan-ignore staticMethod.dynamicCall
                ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe(2);
        }
    });
});
