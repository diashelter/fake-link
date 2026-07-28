<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Services;

use Modules\Auth\Domain\ValueObjects\EmailActionTokenId;

interface EmailActionTokenIdGenerator
{
    public function generate(): EmailActionTokenId;
}
