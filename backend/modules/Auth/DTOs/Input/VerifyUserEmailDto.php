<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Input;

use Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal;

final readonly class VerifyUserEmailDto
{
    public function __construct(
        public AuthenticatedPrincipal $principal,
        public string $plainTextEmailToken,
    ) {}
}
