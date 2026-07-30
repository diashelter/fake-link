<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Repositories;

use DateTimeImmutable;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Output\UserProfileDto;

interface UserRepository
{
    public function nextIdentity(): UserId;

    public function existsByEmail(EmailAddress $email): bool;

    public function findByEmail(EmailAddress $email): ?User;

    public function findById(UserId $id): ?User;

    public function findProfileById(UserId $id): ?UserProfileDto;

    public function save(User $user): void;

    public function update(User $user, ?DateTimeImmutable $updatedAt = null): void;
}
