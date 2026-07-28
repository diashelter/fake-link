<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendPasswordResetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $encryptedToken,
    ) {}

    public function handle(): void
    {
        // Mail delivery is implemented by the password-reset mail pipeline task.
    }
}
