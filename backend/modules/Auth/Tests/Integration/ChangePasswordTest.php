<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\ChangePasswordDto;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Exceptions\PasswordReusedException;
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
use Modules\Auth\UseCases\ChangePassword;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\RevokeAllUserTokens;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeChangePasswordUseCase(): ChangePassword
{
    return new ChangePassword(
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

function makeIssueAuthTokenForChange(): IssueAuthToken
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
function activeUserWithSessionPrincipal(string $currentPassword = 'CurrentPass1!xx'): array
{
    $hasher = new LaravelPasswordHasher;
    $user = UserModel::factory()->active()->create([
        'email' => 'change.user@example.com',
        'password' => $hasher->hash($currentPassword),
    ]);
    $userId = UserId::fromString($user->id);

    $bearer = makeIssueAuthTokenForChange()->execute(
        new IssueAuthTokenDto($userId, TokenKind::Session),
    );
    makeIssueAuthTokenForChange()->execute(
        new IssueAuthTokenDto($userId, TokenKind::Session),
    );

    $principal = new AuthenticatedPrincipalRecord(
        userId: $userId,
        userStatus: UserStatus::Active,
        tokenKind: TokenKind::Session,
        tokenId: $bearer->tokenId,
        expiresAt: $bearer->expiresAt,
    );

    return [
        'user' => $user->fresh(),
        'principal' => $principal,
        'currentPassword' => $currentPassword,
    ];
}

describe('ChangePassword', function () {
    it('updates the password hash and revokes all bearers on success', function () {
        $fixture = activeUserWithSessionPrincipal();
        $hasher = new LaravelPasswordHasher;
        $newPassword = 'BrandNewPass1!x';

        makeChangePasswordUseCase()->execute(
            $fixture['principal'],
            new ChangePasswordDto(
                currentPassword: $fixture['currentPassword'],
                plainTextPassword: $newPassword,
            ),
        );

        $user = UserModel::query()->find($fixture['user']->id);

        expect($user)->not->toBeNull()
            ->and($hasher->verify($newPassword, $user->password))->toBeTrue()
            ->and($hasher->verify($fixture['currentPassword'], $user->password))->toBeFalse()
            ->and($user->status)->toBe(UserStatus::Active->value)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe(0);
    });

    it('rejects a wrong current password without changing hash or revoking tokens', function () {
        $fixture = activeUserWithSessionPrincipal();
        $passwordBefore = $fixture['user']->password;
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count();

        expect(fn () => makeChangePasswordUseCase()->execute(
            $fixture['principal'],
            new ChangePasswordDto(
                currentPassword: 'WrongCurrent1!xx',
                plainTextPassword: 'BrandNewPass1!x',
            ),
        ))->toThrow(InvalidCredentialsException::class, 'The provided credentials are invalid.');

        expect(UserModel::query()->find($fixture['user']->id)?->password)->toBe($passwordBefore)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe($tokenCountBefore);
    });

    it('rejects a reused password without changing hash or revoking tokens', function () {
        $fixture = activeUserWithSessionPrincipal('SamePass1!xxxxxx');
        $passwordBefore = $fixture['user']->password;
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count();

        expect(fn () => makeChangePasswordUseCase()->execute(
            $fixture['principal'],
            new ChangePasswordDto(
                currentPassword: 'SamePass1!xxxxxx',
                plainTextPassword: 'SamePass1!xxxxxx',
            ),
        ))->toThrow(PasswordReusedException::class, PasswordReusedException::MESSAGE);

        expect(UserModel::query()->find($fixture['user']->id)?->password)->toBe($passwordBefore)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe($tokenCountBefore);
    });

    it('leaves user status unchanged after a successful password change', function () {
        $fixture = activeUserWithSessionPrincipal();

        makeChangePasswordUseCase()->execute(
            $fixture['principal'],
            new ChangePasswordDto(
                currentPassword: $fixture['currentPassword'],
                plainTextPassword: 'AnotherNewPass1!',
            ),
        );

        expect(UserModel::query()->find($fixture['user']->id)?->status)->toBe(UserStatus::Active->value)
            ->and(UserModel::query()->find($fixture['user']->id)?->email_verified_at)->not->toBeNull();
    });

    it('rejects change when the principal user no longer exists', function () {
        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString('01901234-5678-7abc-89ab-cdef01234567'),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Session,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234568'),
            expiresAt: new DateTimeImmutable('2026-08-01T00:00:00+00:00'),
        );

        expect(fn () => makeChangePasswordUseCase()->execute(
            $principal,
            new ChangePasswordDto(
                currentPassword: 'CurrentPass1!xx',
                plainTextPassword: 'BrandNewPass1!x',
            ),
        ))->toThrow(InvalidCredentialsException::class);
    });
});
