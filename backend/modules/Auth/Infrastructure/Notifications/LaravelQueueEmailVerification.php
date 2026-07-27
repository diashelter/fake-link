<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Notifications;

use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;

final class LaravelQueueEmailVerification implements QueueEmailVerification
{
    public function dispatch(UserId $userId): void
    {
        SendEmailVerificationJob::dispatch($userId->value())->onQueue('notifications');
    }
}
