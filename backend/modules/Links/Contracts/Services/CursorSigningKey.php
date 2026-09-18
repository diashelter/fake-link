<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

interface CursorSigningKey
{
    public function value(): string;
}
