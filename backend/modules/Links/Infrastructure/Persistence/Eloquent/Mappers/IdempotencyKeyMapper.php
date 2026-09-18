<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\DTOs\Output\IdempotencyLookup;
use Modules\Links\DTOs\Output\IdempotencyRecord;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\IdempotencyKeyModel;
use RuntimeException;

final class IdempotencyKeyMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toReservePersistence(
        UserId $userId,
        string $keyHash,
        string $requestFingerprint,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): array {
        return [
            'user_id' => $userId->value(),
            'key_hash' => $keyHash,
            'request_fingerprint' => $requestFingerprint,
            'response_snapshot' => null,
            'key_id' => null,
            'created_at' => Carbon::instance($createdAt),
            'expires_at' => Carbon::instance($expiresAt),
        ];
    }

    public function toRecord(IdempotencyKeyModel $model): IdempotencyRecord
    {
        $snapshot = $this->normalizeSnapshot($model->response_snapshot);

        if ($snapshot === null || $model->key_id === null) {
            throw new RuntimeException('Idempotency record is incomplete: missing encrypted snapshot.');
        }

        return new IdempotencyRecord(
            userId: UserId::fromString($model->user_id),
            keyHash: $model->key_hash,
            requestFingerprint: $model->request_fingerprint,
            responseSnapshot: $snapshot,
            keyId: $model->key_id,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
            expiresAt: DateTimeImmutable::createFromInterface($model->expires_at),
        );
    }

    public function toLookup(IdempotencyKeyModel $model): IdempotencyLookup
    {
        return new IdempotencyLookup(
            userId: UserId::fromString($model->user_id),
            keyHash: $model->key_hash,
            requestFingerprint: $model->request_fingerprint,
            responseSnapshot: $this->normalizeSnapshot($model->response_snapshot),
            keyId: $model->key_id,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
            expiresAt: DateTimeImmutable::createFromInterface($model->expires_at),
        );
    }

    /**
     * @param  string|resource|null  $value
     */
    private function normalizeSnapshot(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? null : $contents;
        }

        if (is_string($value)) {
            // PostgreSQL may return hex-escaped bytea as \x...
            if (str_starts_with($value, '\\x')) {
                $decoded = hex2bin(substr($value, 2));

                return $decoded === false ? null : $decoded;
            }

            return $value;
        }

        return null;
    }
}
