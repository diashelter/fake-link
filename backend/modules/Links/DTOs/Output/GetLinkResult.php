<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

final readonly class GetLinkResult
{
    public function __construct(
        public LinkDetailDto $link,
        public string $etag,
    ) {}
}
