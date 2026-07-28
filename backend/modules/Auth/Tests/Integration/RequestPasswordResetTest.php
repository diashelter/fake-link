<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Contracts\Services\PasswordHasher;
use Modules\Auth\Contracts\Services\QueuePasswordReset;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\RequestPasswordResetDto;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7EmailActionTokenIdGenerator;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Jobs\SendPasswordResetJob;
use Modules\Auth\Infrastructure\Notifications\LaravelQueuePasswordReset;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssuePasswordResetToken;
use Modules\Auth\UseCases\RequestPasswordReset;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

final class RequestPasswordResetRecordingHasher implements PasswordHasher
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

function makeRequestPasswordReset(?PasswordHasher $passwordHasher = null, ?QueuePasswordReset $queue = null): RequestPasswordReset
{
    return new RequestPasswordReset(
        userRepository: new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ),
        passwordHasher: $passwordHasher ?? new LaravelPasswordHasher,
        queuePasswordReset: $queue ?? new LaravelQueuePasswordReset(
            new IssuePasswordResetToken(
                emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
                emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
                bearerTokenGenerator: new BearerTokenGenerator,
                tokenHasher: new Sha256TokenHasher,
            ),
        ),
    );
}

describe('RequestPasswordReset', function () {
    it('issues one password reset token and enqueues one job for an active user', function () {
        Queue::fake();

        $hasher = new RequestPasswordResetRecordingHasher;
        $user = UserModel::factory()->active()->create([
            'email' => 'active.reset@example.com',
        ]);

        makeRequestPasswordReset($hasher)->execute(new RequestPasswordResetDto('active.reset@example.com'));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->where('purpose', 'password_reset')->count())->toBe(1)
            ->and($hasher->verifyCalls)->toHaveCount(1)
            ->and($hasher->verifyCalls[0]['hash'])->toBe((string) config('auth.dummy_password_hash'));

        Queue::assertPushedOn('notifications', SendPasswordResetJob::class);
        Queue::assertPushed(SendPasswordResetJob::class, 1);
    });

    it('returns without token or job when the email is unknown', function () {
        Queue::fake();

        $hasher = new RequestPasswordResetRecordingHasher;

        makeRequestPasswordReset($hasher)->execute(new RequestPasswordResetDto('missing@example.com'));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0)
            ->and($hasher->verifyCalls)->toHaveCount(1)
            ->and($hasher->verifyCalls[0]['hash'])->toBe((string) config('auth.dummy_password_hash'));

        Queue::assertNothingPushed();
    });

    it('returns without token or job when the user is pending verification', function () {
        Queue::fake();

        $hasher = new RequestPasswordResetRecordingHasher;
        UserModel::factory()->create([
            'email' => 'pending.reset@example.com',
            'status' => UserStatus::PendingVerification->value,
        ]);

        makeRequestPasswordReset($hasher)->execute(new RequestPasswordResetDto('pending.reset@example.com'));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0)
            ->and($hasher->verifyCalls)->toHaveCount(1);

        Queue::assertNothingPushed();
    });

    it('returns without token or job when the user is suspended', function () {
        Queue::fake();

        $hasher = new RequestPasswordResetRecordingHasher;
        UserModel::factory()->suspended()->create([
            'email' => 'suspended.reset@example.com',
        ]);

        makeRequestPasswordReset($hasher)->execute(new RequestPasswordResetDto('suspended.reset@example.com'));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0)
            ->and($hasher->verifyCalls)->toHaveCount(1);

        Queue::assertNothingPushed();
    });

    it('returns without token or job when the user is deletion pending', function () {
        Queue::fake();

        $hasher = new RequestPasswordResetRecordingHasher;
        UserModel::factory()->deletionPending()->create([
            'email' => 'deletion.reset@example.com',
        ]);

        makeRequestPasswordReset($hasher)->execute(new RequestPasswordResetDto('deletion.reset@example.com'));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0)
            ->and($hasher->verifyCalls)->toHaveCount(1);

        Queue::assertNothingPushed();
    });

    it('always runs PasswordHasher::verify against the dummy hash before deciding eligibility', function () {
        Queue::fake();

        $hasher = new RequestPasswordResetRecordingHasher;
        $queue = new class implements QueuePasswordReset
        {
            public bool $dispatched = false;

            public function dispatch(UserId $userId): void
            {
                $this->dispatched = true;
            }
        };

        UserModel::factory()->active()->create(['email' => 'timing.active@example.com']);

        makeRequestPasswordReset($hasher, $queue)->execute(new RequestPasswordResetDto('timing.active@example.com'));
        makeRequestPasswordReset($hasher, $queue)->execute(new RequestPasswordResetDto('timing.missing@example.com'));

        expect($hasher->verifyCalls)->toHaveCount(2)
            ->and($hasher->verifyCalls[0]['hash'])->toBe((string) config('auth.dummy_password_hash'))
            ->and($hasher->verifyCalls[1]['hash'])->toBe((string) config('auth.dummy_password_hash'))
            ->and($queue->dispatched)->toBeTrue();
    });
});
