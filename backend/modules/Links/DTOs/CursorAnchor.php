<?php

declare(strict_types=1);

namespace Modules\Links\DTOs;

use DateTimeImmutable;
use Modules\Links\Domain\ValueObjects\ShortLinkId;

final readonly class CursorAnchor
{
    public function __construct(
        public DateTimeImmutable $createdAt,
        public ShortLinkId $id,
    ) {}
}
