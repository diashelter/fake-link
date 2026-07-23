<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Services;

use Modules\Auth\Domain\ValueObjects\UserId;

interface UserIdGenerator
{
    public function generate(): UserId;
}
