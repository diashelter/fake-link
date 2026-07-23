<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Identity;

use Illuminate\Support\Str;
use Modules\Auth\Contracts\Services\UserIdGenerator;
use Modules\Auth\Domain\ValueObjects\UserId;

final class Uuid7UserIdGenerator implements UserIdGenerator
{
    public function generate(): UserId
    {
        return UserId::fromString((string) Str::uuid7());
    }
}
