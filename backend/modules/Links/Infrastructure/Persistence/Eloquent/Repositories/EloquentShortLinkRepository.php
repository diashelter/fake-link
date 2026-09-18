<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Services\ShortLinkIdGenerator;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\Output\PersistedShortLink;
use Modules\Links\Exceptions\SlugReservationMissing;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\ShortLinkMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;

/**
 * Eloquent adapter for short_links inserts.
 *
 * create() never opens its own transaction — it participates in the caller's.
 * There is deliberately no update path for slug or user_id.
 */
final class EloquentShortLinkRepository implements ShortLinkRepository
{
    public function __construct(
        private readonly ShortLinkIdGenerator $idGenerator,
        private readonly ShortLinkMapper $mapper,
    ) {}

    public function create(
        UserId $ownerId,
        Slug $slug,
        SlugSource $slugSource,
        ?string $title,
        ?DateTimeImmutable $expiresAt,
    ): PersistedShortLink {
        $id = $this->idGenerator->generate();

        try {
            /** @var ShortLinkModel $model */
            $model = ShortLinkModel::query()->create(
                $this->mapper->toPersistence($id, $ownerId, $slug, $slugSource, $title, $expiresAt),
            );
        } catch (QueryException $exception) {
            if ($this->isSlugForeignKeyViolation($exception)) {
                throw SlugReservationMissing::required();
            }

            throw $exception;
        }

        return $this->mapper->toPersisted($model);
    }

    private function isSlugForeignKeyViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        if ($sqlState !== '23503') {
            return false;
        }

        return str_contains($exception->getMessage(), 'short_links_slug_foreign');
    }
}
