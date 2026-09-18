<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Services\LinkDestinationVersionIdGenerator;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\LinkDestinationVersionMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;

/**
 * Eloquent adapter for the first open destination version insert.
 *
 * openFirstVersion() never opens its own transaction — it participates in the
 * caller's. A second open version for the same link fails at the partial unique
 * index (UniqueConstraintViolationException / QueryException).
 */
final class EloquentDestinationVersionRepository implements DestinationVersionRepository
{
    public function __construct(
        private readonly LinkDestinationVersionIdGenerator $idGenerator,
        private readonly LinkDestinationVersionMapper $mapper,
    ) {}

    public function openFirstVersion(
        ShortLinkId $shortLinkId,
        EncryptedDestination $encrypted,
        DateTimeImmutable $validFrom,
    ): LinkDestinationVersionId {
        $id = $this->idGenerator->generate();

        LinkDestinationVersionModel::query()->create(
            $this->mapper->toPersistence($id, $shortLinkId, $encrypted, $validFrom),
        );

        return $id;
    }
}
