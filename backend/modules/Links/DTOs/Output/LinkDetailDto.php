<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;

/**
 * Authorized read projection of a short link. version, blockedAt and user_id
 * are intentionally absent — they are not part of LinkDetail.
 */
final readonly class LinkDetailDto
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
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
