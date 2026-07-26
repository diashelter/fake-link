<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\InviteAllowlist;
use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\Services\PasswordPolicy;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\RegisterUserDto;
use Modules\Auth\Exceptions\AuthDomainException;
use Modules\Auth\Exceptions\InviteAllowlistUnavailableException;
use Modules\Auth\Exceptions\RegistrationNotAllowedException;
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
use Modules\Auth\UseCases\RegisterUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

final class RecordingQueueEmailVerification implements QueueEmailVerification
{
    /** @var list<string> */
    public array $dispatched = [];

    public function dispatch(UserId $userId): void
    {
        $this->dispatched[] = $userId->value();
    }
}

final class AllowlistedEmails implements InviteAllowlist
{
    public function isInvited(EmailAddress $email): bool
    {
        return $email->value() === 'invited@example.com';
    }
}

final class UnavailableInviteAllowlist implements InviteAllowlist
{
    public function isInvited(EmailAddress $email): bool
    {
        throw InviteAllowlistUnavailableException::unavailable();
    }
}

final class FailingAuthTokenRepository implements AuthTokenRepository
{
    public function save(AuthToken $token, string $tokenHash): void
    {
        throw new RuntimeException('token issue failed');
    }

    public function findByHash(string $tokenHash): ?AuthToken
    {
        return null;
    }

    public function deleteById(AuthTokenId $id): void {}

    public function deleteByHash(string $tokenHash): void {}

    public function deleteAllForUser(UserId $userId): int
    {
        return 0;
    }

    public function touchLastUsedAtIfStale(AuthTokenId $id, DateTimeImmutable $now, int $minIntervalSeconds): bool
    {
        return false;
    }
}

final class FailingQueueEmailVerification implements QueueEmailVerification
{
    public function dispatch(UserId $userId): void
    {
        throw new RuntimeException('queue unavailable');
    }
}

final class RaceUserRepository implements UserRepository
{
    public function __construct(private readonly Uuid7UserIdGenerator $ids = new Uuid7UserIdGenerator) {}

    public function nextIdentity(): UserId
    {
        return $this->ids->generate();
    }

    public function existsByEmail(EmailAddress $email): bool
    {
        return false;
    }

    public function findById(UserId $id): ?User
    {
        return null;
    }

    public function save(User $user): void
    {
        throw AuthDomainException::emailAlreadyInUse();
    }
}

function makeRegisterUser(
    ?InviteAllowlist $allowlist = null,
    ?QueueEmailVerification $queue = null,
    ?IssueAuthToken $issueAuthToken = null,
    ?UserRepository $userRepository = null,
): RegisterUser {
    return new RegisterUser(
        inviteAllowlist: $allowlist ?? new AllowlistedEmails,
        userRepository: $userRepository ?? new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        passwordPolicy: new PasswordPolicy,
        passwordHasher: new LaravelPasswordHasher,
        issueAuthToken: $issueAuthToken ?? new IssueAuthToken(
            authTokenRepository: new EloquentAuthTokenRepository(new AuthTokenMapper),
            authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        ),
        queueEmailVerification: $queue ?? new RecordingQueueEmailVerification,
    );
}

function validRegisterDto(string $email = 'invited@example.com'): RegisterUserDto
{
    return new RegisterUserDto(
        name: 'Invited User',
        email: $email,
        plainTextPassword: 'ValidPass1!xy',
    );
}

describe('RegisterUser', function () {
    it('registers an allowlisted user as pending_verification with terms, hashed password, and verification token', function () {
        Carbon::setTestNow('2026-07-26T12:00:00+00:00');

        $queue = new RecordingQueueEmailVerification;
        $registerUser = makeRegisterUser(queue: $queue);
        $result = $registerUser->execute(validRegisterDto());

        $model = UserModel::query()->find($result->user->id()->value());
        $tokenModel = AuthTokenModel::query()->find($result->token->tokenId->value());
        $hasher = new Sha256TokenHasher;

        expect($result->user->status())->toBe(UserStatus::PendingVerification)
            ->and($result->user->emailVerifiedAt())->toBeNull()
            ->and($result->user->termsVersion())->toBe('2026-01')
            ->and($result->user->termsAcceptedAt()->format('Y-m-d\TH:i:sP'))->toBe('2026-07-26T12:00:00+00:00')
            ->and($result->token->tokenKind)->toBe(TokenKind::Verification)
            ->and($result->token->expiresAt->format('Y-m-d\TH:i:sP'))->toBe('2026-07-27T12:00:00+00:00')
            ->and($model)->not->toBeNull()
            ->and($model?->status)->toBe(UserStatus::PendingVerification->value)
            ->and($model?->terms_version)->toBe('2026-01')
            ->and($model?->email_verified_at)->toBeNull()
            ->and($model?->password)->not->toBe('ValidPass1!xy')
            ->and(Hash::check('ValidPass1!xy', (string) $model?->password))->toBeTrue()
            ->and($tokenModel?->token_hash)->toBe($hasher->hash($result->token->plainTextToken))
            ->and($tokenModel?->token_hash)->not->toBe($result->token->plainTextToken)
            ->and($queue->dispatched)->toBe([$result->user->id()->value()]);

        Carbon::setTestNow();
    });

    it('rejects non-invited emails with RegistrationNotAllowedException', function () {
        $registerUser = makeRegisterUser();

        expect(fn () => $registerUser->execute(validRegisterDto('stranger@example.com')))
            ->toThrow(RegistrationNotAllowedException::class, 'Registration is not available for these details.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rejects duplicate emails with the same RegistrationNotAllowedException', function () {
        UserModel::factory()->create(['email' => 'invited@example.com']);
        $registerUser = makeRegisterUser();

        expect(fn () => $registerUser->execute(validRegisterDto('invited@example.com')))
            ->toThrow(RegistrationNotAllowedException::class, 'Registration is not available for these details.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(1)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('maps unique race EMAIL_ALREADY_IN_USE to RegistrationNotAllowedException', function () {
        $alwaysInvited = new class implements InviteAllowlist
        {
            public function isInvited(EmailAddress $email): bool
            {
                return true;
            }
        };
        $registerUser = makeRegisterUser(
            allowlist: $alwaysInvited,
            userRepository: new RaceUserRepository,
        );

        expect(fn () => $registerUser->execute(validRegisterDto('race@example.com')))
            ->toThrow(RegistrationNotAllowedException::class, 'Registration is not available for these details.');
    });

    it('propagates InviteAllowlistUnavailableException when allowlist cannot be consulted', function () {
        $registerUser = makeRegisterUser(allowlist: new UnavailableInviteAllowlist);

        expect(fn () => $registerUser->execute(validRegisterDto()))
            ->toThrow(InviteAllowlistUnavailableException::class, 'The service is temporarily unavailable.');
    });

    it('rolls back the user when IssueAuthToken fails inside the transaction', function () {
        $failingIssue = new IssueAuthToken(
            authTokenRepository: new FailingAuthTokenRepository,
            authTokenIdGenerator: new Uuid7AuthTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        );
        $registerUser = makeRegisterUser(issueAuthToken: $failingIssue);

        expect(fn () => $registerUser->execute(validRegisterDto()))
            ->toThrow(RegistrationNotAllowedException::class, 'Registration is not available for these details.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('keeps registration successful when post-commit email queue dispatch fails', function () {
        $registerUser = makeRegisterUser(queue: new FailingQueueEmailVerification);

        $result = $registerUser->execute(validRegisterDto());

        expect($result->user->status())->toBe(UserStatus::PendingVerification)
            ->and(UserModel::query()->find($result->user->id()->value()))->not->toBeNull()
            ->and(AuthTokenModel::query()->find($result->token->tokenId->value()))->not->toBeNull();
    });
});
