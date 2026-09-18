<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

use Modules\Links\DTOs\Output\EncryptedIdempotencySnapshot;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;
use Modules\Links\Exceptions\IdempotencySnapshotDecryptionFailed;

/**
 * Encrypts and authenticates idempotent create-link response snapshots.
 * Uses an exclusive idempotency keyring — never the destination keyring.
 */
interface IdempotencySnapshotCipher
{
    public function encrypt(IdempotencyResponseSnapshot $snapshot): EncryptedIdempotencySnapshot;

    /**
     * @throws IdempotencySnapshotDecryptionFailed on any failure (tampered, wrong key, malformed, unknown version)
     */
    public function decrypt(EncryptedIdempotencySnapshot $encrypted): IdempotencyResponseSnapshot;
}
