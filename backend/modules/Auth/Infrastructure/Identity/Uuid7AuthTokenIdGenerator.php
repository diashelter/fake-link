<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Identity;

use Illuminate\Support\Str;
use Modules\Auth\Contracts\Services\AuthTokenIdGenerator;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;

final class Uuid7AuthTokenIdGenerator implements AuthTokenIdGenerator
{
    public function generate(): AuthTokenId
    {
        return AuthTokenId::fromString((string) Str::uuid7());
    }
}
