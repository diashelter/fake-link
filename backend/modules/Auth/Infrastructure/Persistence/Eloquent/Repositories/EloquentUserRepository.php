<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Contracts\Services\UserIdGenerator;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\AuthDomainException;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;

final class EloquentUserRepository implements UserRepository
{
    public function __construct(
        private readonly UserIdGenerator $userIdGenerator,
        private readonly UserMapper $userMapper,
    ) {}

    public function nextIdentity(): UserId
    {
        return $this->userIdGenerator->generate();
    }

    public function existsByEmail(EmailAddress $email): bool
    {
        return UserModel::query()
            ->where('email', $email->value())
            ->exists();
    }

    public function save(User $user): void
    {
        try {
            UserModel::query()->create($this->userMapper->toPersistence($user));
        } catch (UniqueConstraintViolationException) {
            throw AuthDomainException::emailAlreadyInUse();
        }
    }
}
