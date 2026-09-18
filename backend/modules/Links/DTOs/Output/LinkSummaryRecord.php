<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;

final readonly class LinkSummaryRecord
{
    public function __construct(
        public string $id,
        public string $slug,
        public SlugSource $slugSource,
        public ?string $title,
        public bool $isEnabled,
        public LinkStatus $status,
        public ?DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
