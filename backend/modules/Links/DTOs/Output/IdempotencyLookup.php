<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\UserId;

/**
 * Non-expired idempotency_keys row, completed or still reserved.
 */
final readonly class IdempotencyLookup
{
    /**
     * @param  string|null  $responseSnapshot  Raw encrypted bytes when completed; null while reserved.
     */
    public function __construct(
        public UserId $userId,
        public string $keyHash,
        public string $requestFingerprint,
        public ?string $responseSnapshot,
        public ?string $keyId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isCompleted(): bool
    {
        return $this->responseSnapshot !== null && $this->keyId !== null;
    }
}
