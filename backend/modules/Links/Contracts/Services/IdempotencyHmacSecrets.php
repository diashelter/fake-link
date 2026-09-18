<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

/**
 * Purpose-distinct HMAC secrets for idempotency key hashing and command fingerprints.
 *
 * Domain never reads config(); Infrastructure supplies these values.
 */
interface IdempotencyHmacSecrets
{
    /** HMAC key used only for hashing the raw Idempotency-Key. */
    public function keyHashSecret(): string;

    /** HMAC key used only for hashing the canonical create-link command. */
    public function fingerprintSecret(): string;
}
