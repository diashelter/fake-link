<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

final readonly class LinkPageRecords
{
    /**
     * @param  list<LinkSummaryRecord>  $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
    ) {}
}
