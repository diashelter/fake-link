<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\UserId;

/**
 * Persisted idempotency_keys row after a confirmed creation snapshot.
 */
final readonly class IdempotencyRecord
{
    /**
     * @param  string  $responseSnapshot  Raw encrypted envelope bytes (never plaintext).
     */
    public function __construct(
        public UserId $userId,
        public string $keyHash,
        public string $requestFingerprint,
        public string $responseSnapshot,
        public string $keyId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
