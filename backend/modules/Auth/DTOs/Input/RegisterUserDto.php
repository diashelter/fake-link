<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Input;

final readonly class RegisterUserDto
{
    public function __construct(
        public string $name,
        public string $email,
        public string $plainTextPassword,
    ) {}
}
