<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Identity;

use Illuminate\Support\Str;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;

final class Uuid7LinkDestinationVersionIdGenerator implements LinkDestinationVersionIdGenerator
{
    public function generate(): LinkDestinationVersionId
    {
        return LinkDestinationVersionId::fromString((string) Str::uuid7());
    }
}
