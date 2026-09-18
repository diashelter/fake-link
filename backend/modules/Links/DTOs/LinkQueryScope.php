<?php

declare(strict_types=1);

namespace Modules\Links\DTOs;

use Modules\Links\Domain\Enums\LinkStatus;

final readonly class LinkQueryScope
{
    public function __construct(
        public ?string $search,
        public ?LinkStatus $status,
    ) {}

    public function equals(self $other): bool
    {
        return $this->search === $other->search
            && $this->status === $other->status;
    }
}
