<?php

declare(strict_types=1);

namespace Modules\Links\UseCases;

use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\CursorCodec;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkPage;
use Modules\Links\Exceptions\InvalidCursor;

final readonly class ListLinks
{
    public function __construct(
        private LinkQueryRepository $queries,
        private CursorCodec $cursors,
        private Clock $clock,
    ) {}

    /**
     * @throws InvalidCursor
     */
    public function execute(UserId $ownerId, ListLinksQuery $query, ?string $cursor): LinkPage
    {
        $now = $this->clock->now();
        $anchor = $cursor === null
            ? null
            : $this->cursors->decode($cursor, $query->scope());

        $page = $this->queries->listForOwner($ownerId, $query, $anchor, $now);

        if (! $page->hasMore || $page->items === []) {
            return new LinkPage(
                items: $page->items,
                nextCursor: null,
                perPage: $query->perPage,
            );
        }

        $last = $page->items[count($page->items) - 1];

        return new LinkPage(
            items: $page->items,
            nextCursor: $this->cursors->encode(
                new CursorAnchor($last->createdAt, ShortLinkId::fromString($last->id)),
                $query->scope(),
            ),
            perPage: $query->perPage,
        );
    }
}
