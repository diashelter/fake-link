<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Exceptions\AuthTokenException;
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
use Modules\Auth\UseCases\ValidateAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeValidateAuthTokenUseCase(): ValidateAuthToken
{
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    return new ValidateAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        userRepository: new EloquentUserRepository(new Uuid7UserIdGenerator, new UserMapper),
        tokenHasher: new Sha256TokenHasher,
    );
}

function issueTokenForUser(UserModel $user, TokenKind $kind = TokenKind::Session): string
{
    $issued = (new IssueAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    ))->execute(new IssueAuthTokenDto(UserId::fromString($user->id), $kind));

    return $issued->plainTextToken;
}

describe('ValidateAuthToken', function () {
    it('returns an authenticated principal for a valid token', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $plainText = issueTokenForUser($user);

        $principal = makeValidateAuthTokenUseCase()->execute($plainText);

        expect($principal->userId()->value())->toBe($user->id)
            ->and($principal->userStatus())->toBe(UserStatus::Active)
            ->and($principal->tokenKind())->toBe(TokenKind::Session);

        Carbon::setTestNow();
    });

    it('rejects unknown tokens as unauthenticated', function () {
        makeValidateAuthTokenUseCase()->execute('unknown-token-value');
    })->throws(AuthTokenException::class, 'Authentication is required.');

    it('rejects absolutely expired tokens without updating last_used_at', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $plainText = issueTokenForUser($user, TokenKind::Verification);

        Carbon::setTestNow('2026-01-02T00:00:00+00:00');

        expect(fn () => makeValidateAuthTokenUseCase()->execute($plainText))
            ->toThrow(AuthTokenException::class, 'Authentication is required.');

        expect(AuthTokenModel::query()->first()?->last_used_at)->toBeNull();

        Carbon::setTestNow();
    });

    it('rejects idle expired tokens without updating last_used_at', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $plainText = issueTokenForUser($user, TokenKind::Verification);

        Carbon::setTestNow('2026-01-01T01:00:01+00:00');

        expect(fn () => makeValidateAuthTokenUseCase()->execute($plainText))
            ->toThrow(AuthTokenException::class, 'Authentication is required.');

        expect(AuthTokenModel::query()->first()?->last_used_at)->toBeNull();

        Carbon::setTestNow();
    });

    it('rejects suspended accounts with account suspended', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Suspended->value]);
        $plainText = issueTokenForUser($user);

        makeValidateAuthTokenUseCase()->execute($plainText);
    })->throws(AuthTokenException::class, 'The account is suspended.');

    it('rejects deletion pending accounts with account pending deletion', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::DeletionPending->value]);
        $plainText = issueTokenForUser($user);

        makeValidateAuthTokenUseCase()->execute($plainText);
    })->throws(AuthTokenException::class, 'The account is pending deletion.');

    it('throttles last_used_at updates to once every 15 minutes', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $plainText = issueTokenForUser($user);
        $validate = makeValidateAuthTokenUseCase();

        $validate->execute($plainText);

        expect(AuthTokenModel::query()->first()?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T00:00:00+00:00');

        Carbon::setTestNow('2026-01-01T00:10:00+00:00');
        $validate->execute($plainText);

        expect(AuthTokenModel::query()->first()?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T00:00:00+00:00');

        Carbon::setTestNow('2026-01-01T00:16:00+00:00');
        $validate->execute($plainText);

        expect(AuthTokenModel::query()->first()?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T00:16:00+00:00');

        Carbon::setTestNow();
    });
});
