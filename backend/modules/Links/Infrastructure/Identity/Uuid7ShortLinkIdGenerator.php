<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Identity;

use Illuminate\Support\Str;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Domain\ValueObjects\ShortLinkId;

final class Uuid7ShortLinkIdGenerator implements ShortLinkIdGenerator
{
    public function generate(): ShortLinkId
    {
        return ShortLinkId::fromString((string) Str::uuid7());
    }
}
