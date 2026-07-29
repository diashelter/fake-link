<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Input;

final readonly class ChangePasswordDto
{
    public function __construct(
        public string $currentPassword,
        public string $plainTextPassword,
    ) {}
}
