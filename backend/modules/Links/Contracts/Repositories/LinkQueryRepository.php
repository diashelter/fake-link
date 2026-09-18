<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkPageRecords;
use Modules\Links\DTOs\Output\PersistedLinkDetail;

interface LinkQueryRepository
{
    public function listForOwner(
        UserId $ownerId,
        ListLinksQuery $query,
        ?CursorAnchor $anchor,
        DateTimeImmutable $now,
    ): LinkPageRecords;

    public function findForOwner(UserId $ownerId, ShortLinkId $linkId): ?PersistedLinkDetail;
}
