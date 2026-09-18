<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Modules\Links\DTOs\Output\IdempotencyRecord;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\IdempotencyKeyMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\IdempotencyKeyModel;

/**
 * Eloquent/query adapter for idempotency_keys.
 *
 * Methods never open or commit a transaction — they participate in the caller's.
 */
final class EloquentIdempotencyKeyRepository implements IdempotencyKeyRepository
{
    public function __construct(
        private readonly IdempotencyKeyMapper $mapper,
    ) {}

    public function findActive(
        UserId $userId,
        string $keyHash,
        DateTimeImmutable $now,
    ): ?IdempotencyRecord {
        $row = DB::table('idempotency_keys')
            ->where('user_id', $userId->value())
            ->where('key_hash', $keyHash)
            ->where('expires_at', '>', Carbon::instance($now))
            ->whereNotNull('response_snapshot')
            ->whereNotNull('key_id')
            ->first();

        if ($row === null) {
            return null;
        }

        $model = new IdempotencyKeyModel;
        $model->forceFill((array) $row);
        $model->exists = true;

        return $this->mapper->toRecord($model);
    }

    public function reserve(
        UserId $userId,
        string $keyHash,
        string $requestFingerprint,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void {
        DB::table('idempotency_keys')->insert(
            $this->mapper->toReservePersistence(
                $userId,
                $keyHash,
                $requestFingerprint,
                $createdAt,
                $expiresAt,
            ),
        );
    }

    public function complete(
        UserId $userId,
        string $keyHash,
        string $responseSnapshot,
        string $keyId,
    ): void {
        // Laravel binds strings as text; encode to hex so PostgreSQL stores opaque bytea.
        DB::update(
            'UPDATE idempotency_keys
             SET response_snapshot = decode(?, \'hex\'), key_id = ?
             WHERE user_id = ? AND key_hash = ?',
            [bin2hex($responseSnapshot), $keyId, $userId->value(), $keyHash],
        );
    }

    public function deleteExpired(DateTimeImmutable $now, int $limit): int
    {
        if ($limit < 1) {
            return 0;
        }

        $rows = DB::select(
            'DELETE FROM idempotency_keys
             WHERE ctid IN (
                 SELECT ctid FROM idempotency_keys
                 WHERE expires_at <= ?
                 LIMIT ?
             )
             RETURNING user_id',
            [Carbon::instance($now), $limit],
        );

        return count($rows);
    }
}
