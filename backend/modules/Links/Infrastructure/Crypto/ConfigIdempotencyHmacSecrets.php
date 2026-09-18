<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Crypto;

use Modules\Links\Contracts\Services\IdempotencyHmacSecrets;

final class ConfigIdempotencyHmacSecrets implements IdempotencyHmacSecrets
{
    public function __construct(
        private readonly string $keyHashSecret,
        private readonly string $fingerprintSecret,
    ) {}

    public function keyHashSecret(): string
    {
        return $this->keyHashSecret;
    }

    public function fingerprintSecret(): string
    {
        return $this->fingerprintSecret;
    }
}
