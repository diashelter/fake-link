<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Input\ResetPasswordDto;
use Modules\Auth\Exceptions\InvalidPasswordResetTokenException;
use Modules\Auth\Exceptions\PasswordReusedException;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
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
use Modules\Auth\UseCases\IssuePasswordResetToken;
use Modules\Auth\UseCases\ResetPassword;
use Modules\Auth\UseCases\RevokeAllUserTokens;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeResetPasswordUseCase(): ResetPassword
{
    return new ResetPassword(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        tokenHasher: new Sha256TokenHasher,
        passwordHasher: new LaravelPasswordHasher,
        revokeAllUserTokens: new RevokeAllUserTokens(
            new EloquentAuthTokenRepository(new AuthTokenMapper),
        ),
    );
}

function makeIssuePasswordResetTokenForReset(): IssuePasswordResetToken
{
    return new IssuePasswordResetToken(
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

function makeIssueAuthTokenForReset(): IssueAuthToken
{
    return new IssueAuthToken(
        authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
        authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );
}

/**
 * @return array{user: UserModel, plainToken: string, currentPassword: string}
 */
function activeUserWithPasswordResetToken(string $currentPassword = 'CurrentPass1!xx'): array
{
    $hasher = new LaravelPasswordHasher;
    $user = UserModel::factory()->active()->create([
        'email' => 'reset.user@example.com',
        'password' => $hasher->hash($currentPassword),
        'status' => UserStatus::Active->value,
    ]);

    $issued = makeIssuePasswordResetTokenForReset()->execute(UserId::fromString($user->id));

    makeIssueAuthTokenForReset()->execute(new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session));
    makeIssueAuthTokenForReset()->execute(new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session));

    return [
        'user' => $user->fresh(),
        'plainToken' => $issued->plainTextToken,
        'currentPassword' => $currentPassword,
    ];
}

describe('ResetPassword', function () {
    it('resets the password, marks the token used, revokes all bearers, and leaves status unchanged', function () {
        Carbon::setTestNow('2026-07-28T12:00:00+00:00');

        $fixture = activeUserWithPasswordResetToken();
        $hasher = new LaravelPasswordHasher;
        $newPassword = 'BrandNewPass1!x';

        makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'reset.user@example.com',
            plainTextToken: $fixture['plainToken'],
            plainTextPassword: $newPassword,
        ));

        $user = UserModel::query()->find($fixture['user']->id);
        $token = EmailActionTokenModel::query()->where('user_id', $fixture['user']->id)->where('purpose', 'password_reset')->first();

        expect($user)->not->toBeNull()
            ->and($user->status)->toBe(UserStatus::Active->value)
            ->and($hasher->verify($newPassword, $user->password))->toBeTrue()
            ->and($hasher->verify($fixture['currentPassword'], $user->password))->toBeFalse()
            ->and($token?->used_at)->not->toBeNull()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe(0);

        Carbon::setTestNow();
    });

    it('rejects an unknown token without changing password or tokens', function () {
        $fixture = activeUserWithPasswordResetToken();
        $passwordBefore = $fixture['user']->password;
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count();

        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'reset.user@example.com',
            plainTextToken: 'not-a-real-token',
            plainTextPassword: 'BrandNewPass1!x',
        )))->toThrow(InvalidPasswordResetTokenException::class, InvalidPasswordResetTokenException::MESSAGE);

        expect(UserModel::query()->find($fixture['user']->id)?->password)->toBe($passwordBefore)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe($tokenCountBefore)
            ->and(EmailActionTokenModel::query()->find(
                EmailActionTokenModel::query()->where('user_id', $fixture['user']->id)->value('id')
            )?->used_at)->toBeNull();
    });

    it('rejects an expired token', function () {
        Carbon::setTestNow('2026-07-28T12:00:00+00:00');
        $fixture = activeUserWithPasswordResetToken();

        Carbon::setTestNow('2026-07-28T12:31:00+00:00');

        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'reset.user@example.com',
            plainTextToken: $fixture['plainToken'],
            plainTextPassword: 'BrandNewPass1!x',
        )))->toThrow(InvalidPasswordResetTokenException::class);

        expect(EmailActionTokenModel::query()->where('user_id', $fixture['user']->id)->value('used_at'))->toBeNull();

        Carbon::setTestNow();
    });

    it('rejects an already used token', function () {
        $fixture = activeUserWithPasswordResetToken();

        makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'reset.user@example.com',
            plainTextToken: $fixture['plainToken'],
            plainTextPassword: 'BrandNewPass1!x',
        ));

        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'reset.user@example.com',
            plainTextToken: $fixture['plainToken'],
            plainTextPassword: 'AnotherNewPass1!x',
        )))->toThrow(InvalidPasswordResetTokenException::class);
    });

    it('rejects when email does not match the token owner', function () {
        $fixture = activeUserWithPasswordResetToken();
        $hasher = new LaravelPasswordHasher;
        UserModel::factory()->active()->create([
            'email' => 'other.reset@example.com',
            'password' => $hasher->hash('OtherPass1!xxxx'),
        ]);

        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'other.reset@example.com',
            plainTextToken: $fixture['plainToken'],
            plainTextPassword: 'BrandNewPass1!x',
        )))->toThrow(InvalidPasswordResetTokenException::class);

        expect(EmailActionTokenModel::query()->where('user_id', $fixture['user']->id)->value('used_at'))->toBeNull();
    });

    it('rejects an email verification token used for password reset', function () {
        $hasher = new LaravelPasswordHasher;
        $user = UserModel::factory()->active()->create([
            'email' => 'verify.purpose@example.com',
            'password' => $hasher->hash('CurrentPass1!xx'),
        ]);

        $verification = (new IssueEmailVerificationToken(
            emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
            emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        ))->execute(UserId::fromString($user->id));

        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'verify.purpose@example.com',
            plainTextToken: $verification->plainTextToken,
            plainTextPassword: 'BrandNewPass1!x',
        )))->toThrow(InvalidPasswordResetTokenException::class)
            ->and(EmailActionTokenModel::query()->where('purpose', EmailActionPurpose::EmailVerification->value)->value('used_at'))
            ->toBeNull();
    });

    it('rejects a reused password without consuming the token or revoking bearers', function () {
        $fixture = activeUserWithPasswordResetToken('SamePass1!xxxxxx');
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count();

        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'reset.user@example.com',
            plainTextToken: $fixture['plainToken'],
            plainTextPassword: 'SamePass1!xxxxxx',
        )))->toThrow(PasswordReusedException::class, PasswordReusedException::MESSAGE);

        expect(EmailActionTokenModel::query()->where('user_id', $fixture['user']->id)->value('used_at'))->toBeNull()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe($tokenCountBefore);
    });

    it('allows only one concurrent reset for the same token', function () {
        $fixture = activeUserWithPasswordResetToken();
        $useCase = makeResetPasswordUseCase();
        $results = [];

        DB::transaction(function () use ($useCase, $fixture, &$results): void {
            try {
                $useCase->execute(new ResetPasswordDto(
                    email: 'reset.user@example.com',
                    plainTextToken: $fixture['plainToken'],
                    plainTextPassword: 'FirstWinnerPass1!',
                ));
                $results[] = 'ok';
            } catch (InvalidPasswordResetTokenException) {
                $results[] = 'invalid';
            }

            try {
                $useCase->execute(new ResetPasswordDto(
                    email: 'reset.user@example.com',
                    plainTextToken: $fixture['plainToken'],
                    plainTextPassword: 'SecondLoserPass1!',
                ));
                $results[] = 'ok';
            } catch (InvalidPasswordResetTokenException) {
                $results[] = 'invalid';
            }
        });

        expect($results)->toBe(['ok', 'invalid'])
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $fixture['user']->id)->count())->toBe(0);
    });

    it('rejects reset for an unknown email with the same invalid token error', function () {
        expect(fn () => makeResetPasswordUseCase()->execute(new ResetPasswordDto(
            email: 'nobody@example.com',
            plainTextToken: 'any-token',
            plainTextPassword: 'BrandNewPass1!x',
        )))->toThrow(InvalidPasswordResetTokenException::class, InvalidPasswordResetTokenException::MESSAGE);
    });
});
