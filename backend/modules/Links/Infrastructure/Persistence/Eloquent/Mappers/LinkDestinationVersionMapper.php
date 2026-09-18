<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;
use Modules\Links\Domain\ValueObjects\ShortLinkId;

final class LinkDestinationVersionMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toPersistence(
        LinkDestinationVersionId $id,
        ShortLinkId $shortLinkId,
        EncryptedDestination $encrypted,
        DateTimeImmutable $validFrom,
    ): array {
        return [
            'id' => $id->value(),
            'short_link_id' => $shortLinkId->value(),
            'destination_url' => $encrypted->envelope(),
            'key_id' => $encrypted->keyId(),
            'valid_from' => Carbon::instance($validFrom),
            'valid_to' => null,
        ];
    }
}
