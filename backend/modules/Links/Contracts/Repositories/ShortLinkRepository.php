<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\Output\PersistedShortLink;
use Modules\Links\Exceptions\SlugReservationMissing;

/**
 * Persists short_links rows. Slug and owner are immutable after insert — this
 * port deliberately exposes no update path for either column.
 */
interface ShortLinkRepository
{
    /**
     * Insert a short link with an application-generated UUID v7, participating
     * in the caller's open transaction (this method never opens or commits one).
     *
     * Initial state: is_enabled=true, blocked_at=null, version=1.
     *
     * @throws SlugReservationMissing when the slug FK to slug_reservations fails
     */
    public function create(
        UserId $ownerId,
        Slug $slug,
        SlugSource $slugSource,
        ?string $title,
        ?DateTimeImmutable $expiresAt,
    ): PersistedShortLink;
}
