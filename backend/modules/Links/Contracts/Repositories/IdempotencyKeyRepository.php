<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\DTOs\Output\IdempotencyRecord;

/**
 * Persists idempotency_keys rows. Never opens or commits a transaction —
 * participates in the caller's open transaction when present.
 *
 * The raw Idempotency-Key value is never accepted or stored.
 */
interface IdempotencyKeyRepository
{
    /**
     * Locate a non-expired, completed record for the given user and key hash.
     */
    public function findActive(
        UserId $userId,
        string $keyHash,
        DateTimeImmutable $now,
    ): ?IdempotencyRecord;

    /**
     * Reserve (user_id, key_hash) with the command fingerprint and TTL.
     * Snapshot columns remain unset until complete().
     */
    public function reserve(
        UserId $userId,
        string $keyHash,
        string $requestFingerprint,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void;

    /**
     * Attach the encrypted response snapshot to a reserved key.
     *
     * @param  string  $responseSnapshot  Raw encrypted envelope bytes.
     */
    public function complete(
        UserId $userId,
        string $keyHash,
        string $responseSnapshot,
        string $keyId,
    ): void;

    /**
     * Delete up to $limit rows with expires_at <= $now. Returns rows deleted.
     */
    public function deleteExpired(DateTimeImmutable $now, int $limit): int;
}
