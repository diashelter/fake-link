<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Output;

use DateTimeImmutable;
use Modules\Auth\Domain\Entities\User;

final readonly class UserProfileDto
{
    public function __construct(
        public User $user,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
