<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;

interface LinkDestinationVersionIdGenerator
{
    public function generate(): LinkDestinationVersionId;
}
