<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Contracts\Repositories\EmailActionTokenRepository;
use Modules\Auth\Domain\Entities\EmailActionToken;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\EmailActionTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;

final class EloquentEmailActionTokenRepository implements EmailActionTokenRepository
{
    public function __construct(
        private readonly EmailActionTokenMapper $emailActionTokenMapper,
    ) {}

    public function save(EmailActionToken $token, string $tokenHash): void
    {
        EmailActionTokenModel::query()->create(
            $this->emailActionTokenMapper->toPersistence($token, $tokenHash),
        );
    }

    public function findByHash(string $tokenHash): ?EmailActionToken
    {
        /** @var EmailActionTokenModel|null $model */
        $model = EmailActionTokenModel::query()->where('token_hash', $tokenHash)->first();

        if ($model === null) {
            return null;
        }

        return $this->emailActionTokenMapper->toDomain($model);
    }

    public function invalidateUnusedForUser(UserId $userId, EmailActionPurpose $purpose, DateTimeImmutable $now): int
    {
        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder whereNull)
        return EmailActionTokenModel::query()
            ->where('user_id', $userId->value())
            ->where('purpose', $purpose->value)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::instance($now)]);
    }

    public function consumeForUser(string $tokenHash, UserId $userId, EmailActionPurpose $purpose, DateTimeImmutable $now): bool
    {
        return DB::transaction(function () use ($tokenHash, $userId, $purpose, $now): bool {
            /** @var EmailActionTokenModel|null $model */
            // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder lockForUpdate)
            $model = EmailActionTokenModel::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($model === null) {
                return false;
            }

            if ($model->user_id !== $userId->value()) {
                return false;
            }

            if ($model->purpose !== $purpose->value) {
                return false;
            }

            if ($model->used_at !== null) {
                return false;
            }

            if ($model->expires_at->lessThanOrEqualTo(Carbon::instance($now))) {
                return false;
            }

            $model->used_at = Carbon::instance($now);
            $model->save();

            return true;
        });
    }
}
