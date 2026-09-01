<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Exceptions\SlugPolicyException;

/**
 * A normalized, immutable slug — the public, permanent identifier of a link.
 *
 * Structural validation only: length, character allowlist, alphanumeric
 * boundaries and no consecutive hyphens. The reserved-word denylist lives in
 * SlugPolicy, not here. No framework, no config(), no intl.
 */
final readonly class Slug
{
    private const MIN_ALIAS_LENGTH = 3;

    private const MAX_ALIAS_LENGTH = 48;

    private const GENERATED_LENGTH = 8;

    /** Explicit ASCII case map — locale-independent, never strtolower/mb_strtolower. */
    private const ASCII_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const ASCII_LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private function __construct(
        private string $value,
        private SlugSource $source,
    ) {}

    /**
     * Build a slug from a user-supplied custom alias: normalize first, then
     * validate the structure. Each failure carries a stable SlugRejectionReason.
     */
    public static function fromCustomAlias(string $raw): self
    {
        $normalized = self::normalize($raw);

        self::assertAliasStructure($normalized);

        return new self($normalized, SlugSource::Custom);
    }

    /**
     * Build a slug from an automatically generated candidate: exactly 8
     * characters drawn from [a-z0-9]. No normalization — the input is already
     * canonical by construction.
     */
    public static function fromGenerated(string $candidate): self
    {
        self::assertGeneratedStructure($candidate);

        return new self($candidate, SlugSource::Automatic);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function source(): SlugSource
    {
        return $this->source;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function normalize(string $raw): string
    {
        return strtr(trim($raw), self::ASCII_UPPER, self::ASCII_LOWER);
    }

    private static function assertAliasStructure(string $value): void
    {
        $length = strlen($value);

        if ($length < self::MIN_ALIAS_LENGTH) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::TooShort);
        }

        if ($length > self::MAX_ALIAS_LENGTH) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::TooLong);
        }

        if (preg_match('/^[a-z0-9-]+$/', $value) !== 1) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::InvalidCharacters);
        }

        if (preg_match('/^[a-z0-9].*[a-z0-9]$/', $value) !== 1) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::InvalidBoundary);
        }

        if (str_contains($value, '--')) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::ConsecutiveHyphens);
        }
    }

    private static function assertGeneratedStructure(string $candidate): void
    {
        $length = strlen($candidate);

        if ($length < self::GENERATED_LENGTH) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::TooShort);
        }

        if ($length > self::GENERATED_LENGTH) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::TooLong);
        }

        if (preg_match('/^[a-z0-9]+$/', $candidate) !== 1) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::InvalidCharacters);
        }
    }
}
