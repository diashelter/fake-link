<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Services;

use Modules\Auth\Domain\ValueObjects\UserId;

interface QueuePasswordReset
{
    public function dispatch(UserId $userId): void;
}
