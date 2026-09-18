<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;
use Modules\Links\Domain\ValueObjects\ShortLinkId;

/**
 * Persists link_destination_versions rows. openFirstVersion() never opens its
 * own transaction — it participates in the caller's.
 */
interface DestinationVersionRepository
{
    /**
     * Insert the first current destination version for a short link:
     * valid_to = null, valid_from = $validFrom, key_id from the envelope.
     *
     * @return LinkDestinationVersionId application-generated UUID v7
     */
    public function openFirstVersion(
        ShortLinkId $shortLinkId,
        EncryptedDestination $encrypted,
        DateTimeImmutable $validFrom,
    ): LinkDestinationVersionId;
}
