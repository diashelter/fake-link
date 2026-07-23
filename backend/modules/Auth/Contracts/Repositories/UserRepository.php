<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Repositories;

use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;

interface UserRepository
{
    public function nextIdentity(): UserId;

    public function existsByEmail(EmailAddress $email): bool;

    public function save(User $user): void;
}
