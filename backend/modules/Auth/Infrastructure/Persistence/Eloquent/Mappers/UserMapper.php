<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Entities\User;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;

final class UserMapper
{
    public function toDomain(UserModel $model): User
    {
        return User::create(
            id: UserId::fromString($model->id),
            name: $model->name,
            email: EmailAddress::fromString($model->email),
            passwordHash: $model->password,
            status: UserStatus::fromString($model->status),
            emailVerifiedAt: $model->email_verified_at !== null
                ? DateTimeImmutable::createFromInterface($model->email_verified_at)
                : null,
            termsVersion: $model->terms_version,
            termsAcceptedAt: DateTimeImmutable::createFromInterface($model->terms_accepted_at),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPersistence(User $user): array
    {
        return [
            'id' => $user->id()->value(),
            'name' => $user->name(),
            'email' => $user->email()->value(),
            'password' => $user->passwordHash(),
            'status' => $user->status()->value,
            'email_verified_at' => $user->emailVerifiedAt() !== null
                ? Carbon::instance($user->emailVerifiedAt())
                : null,
            'terms_version' => $user->termsVersion(),
            'terms_accepted_at' => Carbon::instance($user->termsAcceptedAt()),
        ];
    }
}
