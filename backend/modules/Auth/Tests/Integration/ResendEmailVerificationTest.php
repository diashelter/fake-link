<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Infrastructure\Authentication\AuthenticatedPrincipalRecord;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7EmailActionTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Notifications\LaravelQueueEmailVerification;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Modules\Auth\UseCases\ResendEmailVerification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeResendEmailVerificationUseCase(?QueueEmailVerification $queue = null): ResendEmailVerification
{
    $issue = new IssueEmailVerificationToken(
        emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
        emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
        bearerTokenGenerator: new BearerTokenGenerator,
        tokenHasher: new Sha256TokenHasher,
    );

    return new ResendEmailVerification(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        queueEmailVerification: $queue ?? new LaravelQueueEmailVerification($issue),
    );
}

describe('ResendEmailVerification', function () {
    it('issues a new token, invalidates the previous unused token, and enqueues the mail job', function () {
        Carbon::setTestNow('2026-07-27T16:00:00+00:00');
        Queue::fake();

        $user = UserModel::factory()->create();
        $userId = UserId::fromString($user->id);
        $issue = new IssueEmailVerificationToken(
            emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
            emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
            bearerTokenGenerator: new BearerTokenGenerator,
            tokenHasher: new Sha256TokenHasher,
        );
        $first = $issue->execute($userId);

        $principal = new AuthenticatedPrincipalRecord(
            userId: $userId,
            userStatus: UserStatus::PendingVerification,
            tokenKind: TokenKind::Verification,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234567'),
            expiresAt: Carbon::now()->addDay()->toDateTimeImmutable(),
        );

        makeResendEmailVerificationUseCase(new LaravelQueueEmailVerification($issue))->execute($principal);

        $firstModel = EmailActionTokenModel::query()->find($first->id->value());
        // @phpstan-ignore staticMethod.dynamicCall
        $tokens = EmailActionTokenModel::query()->where('user_id', $user->id)->orderBy('created_at')->get();

        expect($tokens)->toHaveCount(2)
            ->and($firstModel?->used_at?->toIso8601String())->toBe('2026-07-27T16:00:00+00:00')
            ->and($tokens->last()?->used_at)->toBeNull();

        Queue::assertPushed(SendEmailVerificationJob::class, 1);
        Queue::assertPushedOn('notifications', SendEmailVerificationJob::class);
        Queue::assertPushed(SendEmailVerificationJob::class, function (SendEmailVerificationJob $job) use ($userId): bool {
            return $job->userId === $userId->value()
                && Crypt::decryptString($job->encryptedToken) !== '';
        });

        Carbon::setTestNow();
    });

    it('rejects resend when the user is already active', function () {
        Queue::fake();

        $user = UserModel::factory()->active()->create();
        $principal = new AuthenticatedPrincipalRecord(
            userId: UserId::fromString($user->id),
            userStatus: UserStatus::Active,
            tokenKind: TokenKind::Verification,
            tokenId: AuthTokenId::fromString('01901234-5678-7abc-89ab-cdef01234568'),
            expiresAt: Carbon::now()->addDay()->toDateTimeImmutable(),
        );

        expect(fn () => makeResendEmailVerificationUseCase()->execute($principal))
            ->toThrow(EmailAlreadyVerifiedException::class);

        Queue::assertNothingPushed();
        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->count())->toBe(0);
    });
});
