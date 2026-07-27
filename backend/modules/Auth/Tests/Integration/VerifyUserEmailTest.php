<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Input\VerifyUserEmailDto;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Exceptions\InvalidVerificationTokenException;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7EmailActionTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Modules\Auth\UseCases\RevokeAuthToken;
use Modules\Auth\UseCases\VerifyUserEmail;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeVerifyUserEmailUseCase(): VerifyUserEmail
{
    $authTokenRepository = new EloquentAuthTokenRepository(new AuthTokenMapper);

    return new VerifyUserEmail(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        tokenHasher: new Sha256TokenHasher,
        revokeAuthToken: new RevokeAuthToken($authTokenRepository),
    );
}

function makeIssueEmailVerificationTokenForVerify(): IssueEmailVerificationToken
{
    return new IssueEmailVerificationToken(
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

function makeIssueAuthTokenForVerify(): IssueAuthToken
{
    return new IssueAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

/**
 * @return array{user: UserModel, principal: AuthenticatedPrincipalRecord, emailToken: string, bearerTokenId: string}
 */
function pendingUserWithVerificationCredentials(): array
{
    $user = UserModel::factory()->create();
    $userId = UserId::fromString($user->id);

    $bearer = makeIssueAuthTokenForVerify()->execute(
        new IssueAuthTokenDto($userId, TokenKind::Verification),
    );
    $emailToken = makeIssueEmailVerificationTokenForVerify()->execute($userId);

    $principal = new AuthenticatedPrincipalRecord(
        userId: $userId,
        userStatus: UserStatus::PendingVerification,
        tokenKind: TokenKind::Verification,
        tokenId: $bearer->tokenId,
        expiresAt: $bearer->expiresAt,
    );

    return [
        'user' => $user,
        'principal' => $principal,
        'emailToken' => $emailToken->plainTextToken,
        'bearerTokenId' => $bearer->tokenId->value(),
    ];
}

describe('VerifyUserEmail', function () {
    it('activates the user, marks the email token used, and revokes the presented bearer without issuing session', function () {
        Carbon::setTestNow('2026-07-27T15:00:00+00:00');

        $fixture = pendingUserWithVerificationCredentials();
        // @phpstan-ignore staticMethod.dynamicCall
        $sessionCountBefore = AuthTokenModel::query()->where('token_kind', TokenKind::Session->value)->count();

        makeVerifyUserEmailUseCase()->execute(new VerifyUserEmailDto(
            principal: $fixture['principal'],
            plainTextEmailToken: $fixture['emailToken'],
        ));

        $user = UserModel::query()->find($fixture['user']->id);
        $emailToken = EmailActionTokenModel::query()->where('user_id', $fixture['user']->id)->first();

        expect($user?->status)->toBe(UserStatus::Active->value)
            ->and($user?->email_verified_at->toIso8601String())->toBe('2026-07-27T15:00:00+00:00')
            ->and($emailToken?->used_at->toIso8601String())->toBe('2026-07-27T15:00:00+00:00')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('id', $fixture['bearerTokenId'])->exists())->toBeFalse()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('token_kind', TokenKind::Session->value)->count())->toBe($sessionCountBefore);

        Carbon::setTestNow();
    });

    it('rejects an invalid email token with INVALID_VERIFICATION_TOKEN', function () {
        $fixture = pendingUserWithVerificationCredentials();

        expect(fn () => makeVerifyUserEmailUseCase()->execute(new VerifyUserEmailDto(
            principal: $fixture['principal'],
            plainTextEmailToken: 'not-a-real-token',
        )))->toThrow(InvalidVerificationTokenException::class);

        expect(UserModel::query()->find($fixture['user']->id)?->status)
            ->toBe(UserStatus::PendingVerification->value)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('id', $fixture['bearerTokenId'])->exists())->toBeTrue();
    });

    it('rejects an expired email token', function () {
        Carbon::setTestNow('2026-07-27T15:00:00+00:00');
        $fixture = pendingUserWithVerificationCredentials();

        EmailActionTokenModel::query()
            ->where('user_id', $fixture['user']->id)
            ->update(['expires_at' => Carbon::parse('2026-07-27T14:00:00+00:00')]);

        expect(fn () => makeVerifyUserEmailUseCase()->execute(new VerifyUserEmailDto(
            principal: $fixture['principal'],
            plainTextEmailToken: $fixture['emailToken'],
        )))->toThrow(InvalidVerificationTokenException::class);

        Carbon::setTestNow();
    });

    it('rejects an already used email token while the user remains pending', function () {
        Carbon::setTestNow('2026-07-27T15:00:00+00:00');
        $fixture = pendingUserWithVerificationCredentials();

        EmailActionTokenModel::query()
            ->where('user_id', $fixture['user']->id)
            ->update(['used_at' => Carbon::parse('2026-07-27T14:30:00+00:00')]);

        expect(fn () => makeVerifyUserEmailUseCase()->execute(new VerifyUserEmailDto(
            principal: $fixture['principal'],
            plainTextEmailToken: $fixture['emailToken'],
        )))->toThrow(InvalidVerificationTokenException::class);

        expect(UserModel::query()->find($fixture['user']->id)?->status)
            ->toBe(UserStatus::PendingVerification->value)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('id', $fixture['bearerTokenId'])->exists())->toBeTrue();

        Carbon::setTestNow();
    });

    it('rejects verify when the user is already active before consuming the token', function () {
        $user = UserModel::factory()->active()->create();
        $userId = UserId::fromString($user->id);
        $emailToken = makeIssueEmailVerificationTokenForVerify()->execute($userId);
        $bearer = makeIssueAuthTokenForVerify()->execute(
            new IssueAuthTokenDto($userId, TokenKind::Verification),
        );

        expect(fn () => makeVerifyUserEmailUseCase()->execute(new VerifyUserEmailDto(
            principal: new AuthenticatedPrincipalRecord(
                userId: $userId,
                userStatus: UserStatus::Active,
                tokenKind: TokenKind::Verification,
                tokenId: $bearer->tokenId,
                expiresAt: $bearer->expiresAt,
            ),
            plainTextEmailToken: $emailToken->plainTextToken,
        )))->toThrow(EmailAlreadyVerifiedException::class);

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))->toBeNull()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('id', $bearer->tokenId->value())->exists())->toBeTrue();
    });

    it('rejects an email token belonging to another user', function () {
        $owner = pendingUserWithVerificationCredentials();
        $intruder = pendingUserWithVerificationCredentials();

        expect(fn () => makeVerifyUserEmailUseCase()->execute(new VerifyUserEmailDto(
            principal: $intruder['principal'],
            plainTextEmailToken: $owner['emailToken'],
        )))->toThrow(InvalidVerificationTokenException::class);

        expect(UserModel::query()->find($intruder['user']->id)?->status)
            ->toBe(UserStatus::PendingVerification->value)
            ->and(UserModel::query()->find($owner['user']->id)?->status)
            ->toBe(UserStatus::PendingVerification->value);
    });
});
