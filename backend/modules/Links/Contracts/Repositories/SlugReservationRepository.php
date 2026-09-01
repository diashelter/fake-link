<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Repositories;

use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugUnavailable;

/**
 * Persists slug reservations. The namespace is global and permanent: there is
 * deliberately no method to delete, truncate or update a reservation — once a
 * slug is taken it stays taken, even after the link that used it is gone.
 */
interface SlugReservationRepository
{
    /**
     * Insert a reservation for $slug, participating in the caller's open
     * transaction (this method never opens or commits one of its own).
     *
     * @throws SlugUnavailable if the slug is already reserved
     */
    public function reserve(Slug $slug): void;

    /**
     * True when a reservation for $slug exists but no short link references it
     * — an orphan reservation, a valid and final state.
     */
    public function existsWithoutLink(Slug $slug): bool;
}
