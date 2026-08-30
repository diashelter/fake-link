<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

use Modules\Links\Domain\ValueObjects\ShortLinkId;

interface ShortLinkIdGenerator
{
    public function generate(): ShortLinkId;
}
