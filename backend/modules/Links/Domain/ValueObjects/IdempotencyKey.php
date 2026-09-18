<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

use Modules\Links\Exceptions\LinksDomainException;

/**
 * Validated Idempotency-Key header value.
 *
 * Structural validation only: length 16–128 and ASCII allowlist.
 * The raw value must never be persisted — only purpose-scoped HMACs.
 */
final readonly class IdempotencyKey
{
    private const MIN_LENGTH = 16;

    private const MAX_LENGTH = 128;

    private const PATTERN = '/\A[A-Za-z0-9._:-]+\z/';

    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        $length = strlen($raw);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || preg_match(self::PATTERN, $raw) !== 1) {
            throw LinksDomainException::invalidIdempotencyKey();
        }

        return new self($raw);
    }

    public function value(): string
    {
        return $this->value;
    }
}
