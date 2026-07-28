<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Identity\Uuid7EmailActionTokenIdGenerator;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Notifications\LaravelQueueEmailVerification;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentEmailActionTokenRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Tests\TestCase;
use Throwable;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

describe('LaravelQueueEmailVerification', function () {
    it('issues a token and dispatches exactly one SendEmailVerificationJob on the notifications queue', function () {
        Queue::fake();

        $user = UserModel::factory()->create();
        $userId = UserId::fromString($user->id);
        $adapter = new LaravelQueueEmailVerification(
            new IssueEmailVerificationToken(
                emailActionTokenRepository: new EloquentEmailActionTokenRepository(new EmailActionTokenMapper),
                emailActionTokenIdGenerator: new Uuid7EmailActionTokenIdGenerator,
                bearerTokenGenerator: new BearerTokenGenerator,
                tokenHasher: new Sha256TokenHasher,
            ),
        );

        $adapter->dispatch($userId);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->count())->toBe(1);

        Queue::assertPushedOn('notifications', SendEmailVerificationJob::class);
        Queue::assertPushed(SendEmailVerificationJob::class, 1);
        Queue::assertPushed(SendEmailVerificationJob::class, function (SendEmailVerificationJob $job) use ($userId): bool {
            if ($job->userId !== $userId->value()) {
                return false;
            }

            expect(fn () => Crypt::decryptString($job->encryptedToken))->not->toThrow(Throwable::class)
                ->and($job->encryptedToken)->not->toBe('');

            return true;
        });
    });
});
