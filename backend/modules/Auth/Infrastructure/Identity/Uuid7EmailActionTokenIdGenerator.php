<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Identity;

use Illuminate\Support\Str;
use Modules\Auth\Contracts\Services\EmailActionTokenIdGenerator;
use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;

final class Uuid7EmailActionTokenIdGenerator implements EmailActionTokenIdGenerator
{
    public function generate(): EmailActionTokenId
    {
        return EmailActionTokenId::fromString((string) Str::uuid7());
    }
}
