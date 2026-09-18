<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Domain\ValueObjects\Slug;

/**
 * Row state returned by ShortLinkRepository::create after a successful insert.
 */
final readonly class PersistedShortLink
{
    public function __construct(
        public ShortLinkId $id,
        public UserId $userId,
        public Slug $slug,
        public SlugSource $slugSource,
        public ?string $title,
        public bool $isEnabled,
        public ?DateTimeImmutable $blockedAt,
        public ?DateTimeImmutable $expiresAt,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
