<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Notifications;

use Illuminate\Support\Facades\Crypt;
use Modules\Auth\Contracts\Services\QueuePasswordReset;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Jobs\SendPasswordResetJob;
use Modules\Auth\UseCases\IssuePasswordResetToken;

final class LaravelQueuePasswordReset implements QueuePasswordReset
{
    public function __construct(
        private readonly IssuePasswordResetToken $issuePasswordResetToken,
    ) {}

    public function dispatch(UserId $userId): void
    {
        $issued = $this->issuePasswordResetToken->execute($userId);

        SendPasswordResetJob::dispatch(
            $userId->value(),
            Crypt::encryptString($issued->plainTextToken),
        )->onQueue('notifications');
    }
}
