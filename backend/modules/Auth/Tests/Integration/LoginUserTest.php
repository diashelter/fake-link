<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\DTOs\Input\LoginUserDto;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7AuthTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentAuthTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\LoginUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

final class RecordingPasswordHasher implements PasswordHasher
{
    /** @var list<array{plainText: string, hash: string}> */
    public array $verifyCalls = [];

    public function __construct(private readonly PasswordHasher $inner = new LaravelPasswordHasher) {}

    public function hash(string $plainText): string
    {
        return $this->inner->hash($plainText);
    }

    public function verify(string $plainText, string $hash): bool
    {
        $this->verifyCalls[] = [
            'plainText' => $plainText,
            'hash' => $hash,
        ];

        return $this->inner->verify($plainText, $hash);
    }
}

function makeLoginUser(?PasswordHasher $passwordHasher = null): LoginUser
{
    return new LoginUser(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        passwordHasher: $passwordHasher ?? new LaravelPasswordHasher,
        issueAuthToken: new IssueAuthToken(
            authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
            authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        ),
    );
}

function persistLoginUser(
    string $email,
    string $plainPassword,
    UserStatus $status,
): User {
    $repository = new EloquentUserRepository(
        userIdGenerator: new Uuid7UserIdGenerator,
        userMapper: new UserMapper,
    );
    $hasher = new LaravelPasswordHasher;
    $now = new DateTimeImmutable('2026-07-01T00:00:00+00:00');

    $user = User::create(
        id: $repository->nextIdentity(),
        name: 'Login User',
        email: EmailAddress::fromString($email),
        passwordHash: $hasher->hash($plainPassword),
        status: $status,
        emailVerifiedAt: $status === UserStatus::Active ? $now : null,
        termsVersion: '2026-01',
        termsAcceptedAt: $now,
    );

    $repository->save($user);

    return $user;
}

function validLoginDto(string $email = 'active@example.com', string $password = 'ValidPass1!xy'): LoginUserDto
{
    return new LoginUserDto(
        email: $email,
        plainTextPassword: $password,
    );
}

describe('LoginUser', function () {
    it('issues a session token for an active user with valid credentials', function () {
        Carbon::setTestNow('2026-07-27T12:00:00+00:00');
        $user = persistLoginUser('active@example.com', 'ValidPass1!xy', UserStatus::Active);

        $result = makeLoginUser()->execute(validLoginDto('active@example.com'));

        expect($result->user->id()->value())->toBe($user->id()->value())
            ->and($result->user->status())->toBe(UserStatus::Active)
            ->and($result->token->tokenKind)->toBe(TokenKind::Session)
            ->and($result->token->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-08-03T12:00:00+00:00')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $user->id()->value())->count())->toBe(1)
            ->and(UserModel::query()->find($user->id()->value())?->status)->toBe(UserStatus::Active->value);

        Carbon::setTestNow();
    });

    it('issues a verification token for a pending_verification user without queueing email', function () {
        Carbon::setTestNow('2026-07-27T12:00:00+00:00');
        Queue::fake();
        $user = persistLoginUser('pending@example.com', 'ValidPass1!xy', UserStatus::PendingVerification);

        $result = makeLoginUser()->execute(validLoginDto('pending@example.com'));

        expect($result->token->tokenKind)->toBe(TokenKind::Verification)
            ->and($result->token->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-07-28T12:00:00+00:00')
            ->and(UserModel::query()->find($user->id()->value())?->status)->toBe(UserStatus::PendingVerification->value);

        Queue::assertNothingPushed();
        Queue::assertNotPushed(SendEmailVerificationJob::class);

        Carbon::setTestNow();
    });

    it('rejects unknown email with InvalidCredentialsException after dummy verify', function () {
        $hasher = new RecordingPasswordHasher;
        $loginUser = makeLoginUser($hasher);

        expect(fn () => $loginUser->execute(validLoginDto('missing@example.com')))
            ->toThrow(InvalidCredentialsException::class, 'The provided credentials are invalid.');

        expect($hasher->verifyCalls)->toHaveCount(1)
            ->and($hasher->verifyCalls[0]['hash'])->toBe((string) config('auth.dummy_password_hash'))
            ->and($hasher->verifyCalls[0]['plainText'])->toBe('ValidPass1!xy')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rejects wrong password with InvalidCredentialsException and no token', function () {
        persistLoginUser('active@example.com', 'ValidPass1!xy', UserStatus::Active);

        expect(fn () => makeLoginUser()->execute(validLoginDto('active@example.com', 'WrongPass1!xy')))
            ->toThrow(InvalidCredentialsException::class, 'The provided credentials are invalid.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rejects wrong password on suspended account as InvalidCredentialsException not 403', function () {
        persistLoginUser('suspended@example.com', 'ValidPass1!xy', UserStatus::Suspended);

        expect(fn () => makeLoginUser()->execute(validLoginDto('suspended@example.com', 'WrongPass1!xy')))
            ->toThrow(InvalidCredentialsException::class, 'The provided credentials are invalid.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rejects suspended account with correct password via AuthTokenException ACCOUNT_SUSPENDED', function () {
        persistLoginUser('suspended@example.com', 'ValidPass1!xy', UserStatus::Suspended);

        try {
            makeLoginUser()->execute(validLoginDto('suspended@example.com'));
            expect(false)->toBeTrue('Expected AuthTokenException');
        } catch (AuthTokenException $exception) {
            expect($exception->errorCode())->toBe(AuthTokenException::ACCOUNT_SUSPENDED)
                ->and($exception->getMessage())->toBe('The account is suspended.');
        }

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rejects deletion_pending account with correct password via AuthTokenException ACCOUNT_PENDING_DELETION', function () {
        persistLoginUser('deleting@example.com', 'ValidPass1!xy', UserStatus::DeletionPending);

        try {
            makeLoginUser()->execute(validLoginDto('deleting@example.com'));
            expect(false)->toBeTrue('Expected AuthTokenException');
        } catch (AuthTokenException $exception) {
            expect($exception->errorCode())->toBe(AuthTokenException::ACCOUNT_PENDING_DELETION)
                ->and($exception->getMessage())->toBe('The account is pending deletion.');
        }

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('allows multiple successful logins without revoking prior tokens', function () {
        $user = persistLoginUser('multi@example.com', 'ValidPass1!xy', UserStatus::Active);
        $loginUser = makeLoginUser();

        $first = $loginUser->execute(validLoginDto('multi@example.com'));
        $second = $loginUser->execute(validLoginDto('multi@example.com'));

        expect($first->token->plainTextToken)->not->toBe($second->token->plainTextToken)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $user->id()->value())->count())->toBe(2)
            ->and(AuthTokenModel::query()->find($first->token->tokenId->value()))->not->toBeNull()
            ->and(AuthTokenModel::query()->find($second->token->tokenId->value()))->not->toBeNull();
    });
});
