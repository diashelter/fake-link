<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Services;

use Modules\Auth\Domain\ValueObjects\AuthTokenId;

interface AuthTokenIdGenerator
{
    public function generate(): AuthTokenId;
}
