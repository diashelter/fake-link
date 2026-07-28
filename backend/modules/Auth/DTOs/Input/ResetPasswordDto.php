<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Input;

final readonly class ResetPasswordDto
{
    public function __construct(
        public string $email,
        public string $plainTextToken,
        public string $plainTextPassword,
    ) {}
}
