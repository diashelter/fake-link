<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;

final class LinkStatusResolver
{
    /**
     * Resolve the effective status of a link.
     *
     * Precedence: blocked > expired (expires_at <= now, exclusive) > inactive > active
     *
     * @param  DateTimeImmutable|null  $blockedAt  Non-null means the link is blocked.
     * @param  DateTimeImmutable|null  $expiresAt  Non-null means the link has an expiry. expires_at <= now means expired.
     * @param  bool  $isEnabled  False means the link is inactive.
     * @param  DateTimeImmutable  $now  The current instant (injected for testability).
     */
    public function resolve(
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
