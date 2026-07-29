<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Input;

final readonly class RequestPasswordResetDto
{
    public function __construct(
        public string $email,
    ) {}
}
