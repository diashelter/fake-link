<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Input;

use DateTimeImmutable;

final readonly class CreateLinkInput
{
    public function __construct(
        public string $destinationUrl,
        public ?string $customAlias,
        public ?string $title,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
