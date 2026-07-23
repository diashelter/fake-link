<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\AuthTokenMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;

final class EloquentAuthTokenRepository implements AuthTokenRepository
{
    public function __construct(
        private readonly AuthTokenMapper $authTokenMapper,
    ) {}

    public function save(AuthToken $token, string $tokenHash): void
    {
        AuthTokenModel::query()->create(
            $this->authTokenMapper->toPersistence($token, $tokenHash),
        );
    }

    public function findByHash(string $tokenHash): ?AuthToken
    {
        /** @var AuthTokenModel|null $model */
        $model = AuthTokenModel::query()->where('token_hash', $tokenHash)->first();

        if ($model === null) {
            return null;
        }

        return $this->authTokenMapper->toDomain($model);
    }

    public function deleteById(AuthTokenId $id): void
    {
        AuthTokenModel::query()->where('id', $id->value())->delete();
    }

    public function deleteByHash(string $tokenHash): void
    {
        AuthTokenModel::query()->where('token_hash', $tokenHash)->delete();
    }

    public function deleteAllForUser(UserId $userId): int
    {
        return AuthTokenModel::query()->where('user_id', $userId->value())->delete();
    }

    public function touchLastUsedAtIfStale(AuthTokenId $id, DateTimeImmutable $now, int $minIntervalSeconds): bool
    {
        $threshold = Carbon::instance($now)->subSeconds($minIntervalSeconds);

        $updated = AuthTokenModel::query()
            ->where('id', $id->value())
            ->where(function ($query) use ($threshold): void {
                // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder whereNull/orWhere)
                $query->whereNull('last_used_at')
                    ->orWhere('last_used_at', '<', $threshold);
            })
            ->update(['last_used_at' => Carbon::instance($now)]);

        return $updated > 0;
    }
}
