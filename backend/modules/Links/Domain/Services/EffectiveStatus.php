<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;

/**
 * Derives the effective public status of a short link.
 *
 * Precedence (docs/data-model.md §4):
 * blocked → expired (expires_at <= now, exclusive upper bound) → inactive → active.
 */
final class EffectiveStatus
{
    public function for(
        ?DateTimeImmutable $blockedAt,
        ?DateTimeImmutable $expiresAt,
        bool $isEnabled,
        DateTimeImmutable $now,
    ): LinkStatus {
        if ($blockedAt !== null) {
            return LinkStatus::Blocked;
        }

        if ($expiresAt !== null && $expiresAt <= $now) {
            return LinkStatus::Expired;
        }

        if (! $isEnabled) {
            return LinkStatus::Inactive;
        }

        return LinkStatus::Active;
    }
}
