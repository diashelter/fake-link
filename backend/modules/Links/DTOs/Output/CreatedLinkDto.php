<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;

/**
 * Post-creation link state for HTTP Resource serialization and ETag sealing.
 * version and blockedAt feed ETag; they are not part of LinkDetail.
 */
final readonly class CreatedLinkDto
{
    public function __construct(
        public string $id,
        public string $slug,
        public SlugSource $slugSource,
        public string $destinationUrl,
        public ?string $title,
        public bool $isEnabled,
        public LinkStatus $status,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $blockedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public int $version,
    ) {}
}
