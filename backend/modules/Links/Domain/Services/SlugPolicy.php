<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugPolicyException;

/**
 * The sole construction path for a Slug. Combines the value object's
 * structural validation with the reserved-word denylist, applied identically
 * to custom aliases and generated candidates.
 *
 * The denylist is checked only after structural validation succeeds, and
 * always against the normalized value.
 */
final readonly class SlugPolicy
{
    public function __construct(private ReservedSlugs $reserved) {}

    public function fromCustomAlias(string $raw): Slug
    {
        return $this->rejectIfReserved(Slug::fromCustomAlias($raw));
    }

    public function fromGenerated(string $candidate): Slug
    {
        return $this->rejectIfReserved(Slug::fromGenerated($candidate));
    }

    public function isReserved(string $normalizedSlug): bool
    {
        return $this->reserved->contains($normalizedSlug);
    }

    private function rejectIfReserved(Slug $slug): Slug
    {
        if ($this->reserved->contains($slug->value())) {
            throw SlugPolicyException::fromReason(SlugRejectionReason::ReservedWord);
        }

        return $slug;
    }
}
