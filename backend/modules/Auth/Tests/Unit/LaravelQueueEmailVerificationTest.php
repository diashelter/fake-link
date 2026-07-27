<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Notifications\LaravelQueueEmailVerification;
use Tests\TestCase;

uses(TestCase::class);

describe('LaravelQueueEmailVerification', function () {
    it('dispatches exactly one SendEmailVerificationJob on the notifications queue', function () {
        Queue::fake();

        $userId = UserId::fromString('01901234-5678-7abc-89ab-cdef01234567');
        $adapter = new LaravelQueueEmailVerification;

        $adapter->dispatch($userId);

        Queue::assertPushedOn('notifications', SendEmailVerificationJob::class);
        Queue::assertPushed(SendEmailVerificationJob::class, 1);
        Queue::assertPushed(SendEmailVerificationJob::class, function (SendEmailVerificationJob $job) use ($userId): bool {
            return $job->userId === $userId->value();
        });
    });

    it('has a no-op handle that does not throw', function () {
        $job = new SendEmailVerificationJob('01901234-5678-7abc-89ab-cdef01234567');

        expect(fn () => $job->handle())->not->toThrow(Throwable::class);
    });
});
