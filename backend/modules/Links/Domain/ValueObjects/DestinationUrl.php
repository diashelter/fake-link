<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

use Modules\Links\Domain\Services\DestinationUrlPolicy;
use Modules\Links\Domain\Services\PublicHostClassifier;

final readonly class DestinationUrl
{
    private function __construct(private string $value) {}

    /**
     * Build a DestinationUrl from a raw string, running the full destination policy
     * (parsing, host classification and normalization). There is no other way to
     * construct this value object — a caller can never obtain one without the policy
     * having run immediately before.
     */
    public static function fromString(string $raw, PublicHostClassifier $hosts): self
    {
        $policy = new DestinationUrlPolicy($hosts);

        return new self($policy->normalize($raw));
    }

    /**
     * The normalized value. There is no accessor for the raw input.
     */
    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
