<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

use DateTimeImmutable;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Domain\ValueObjects\Slug;

final readonly class PersistedLinkDetail
{
    public function __construct(
        public ShortLinkId $id,
        public Slug $slug,
        public SlugSource $slugSource,
        public EncryptedDestination $destination,
        public ?string $title,
        public bool $isEnabled,
        public ?DateTimeImmutable $blockedAt,
        public ?DateTimeImmutable $expiresAt,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
