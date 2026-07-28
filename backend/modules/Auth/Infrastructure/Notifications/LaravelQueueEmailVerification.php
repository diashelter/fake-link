<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Notifications;

use Illuminate\Support\Facades\Crypt;
use Modules\Auth\Contracts\Services\QueueEmailVerification;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\UseCases\IssueEmailVerificationToken;

final class LaravelQueueEmailVerification implements QueueEmailVerification
{
    public function __construct(
        private readonly IssueEmailVerificationToken $issueEmailVerificationToken,
    ) {}

    public function dispatch(UserId $userId): void
    {
        $issued = $this->issueEmailVerificationToken->execute($userId);

        SendEmailVerificationJob::dispatch(
            $userId->value(),
            Crypt::encryptString($issued->plainTextToken),
        )->onQueue('notifications');
    }
}
