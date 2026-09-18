<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

/**
 * AES-256-GCM envelope for an idempotency response snapshot.
 *
 * @param  string  $ciphertext  Raw binary envelope bytes (never plaintext).
 */
final readonly class EncryptedIdempotencySnapshot
{
    public function __construct(
        public string $ciphertext,
        public string $keyId,
    ) {}
}
