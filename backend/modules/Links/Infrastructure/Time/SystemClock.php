<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Time;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Links\Contracts\Services\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
