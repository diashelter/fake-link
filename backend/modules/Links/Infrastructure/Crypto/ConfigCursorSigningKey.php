<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Crypto;

use Modules\Links\Contracts\Services\CursorSigningKey;

final class ConfigCursorSigningKey implements CursorSigningKey
{
    public function __construct(
        private readonly string $key,
    ) {}

    public function value(): string
    {
        return $this->key;
    }
}
