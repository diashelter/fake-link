<?php

declare(strict_types=1);

namespace Modules\Auth\DTOs\Output;

use Modules\Auth\Domain\Entities\User;

final readonly class LoggedInUserDto
{
    public function __construct(
        public User $user,
        public IssuedAuthTokenDto $token,
    ) {}
}
