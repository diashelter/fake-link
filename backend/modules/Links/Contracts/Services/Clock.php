<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
