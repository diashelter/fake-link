<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\IdempotencyKeyMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentIdempotencyKeyRepository;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    $this->repo = new EloquentIdempotencyKeyRepository(new IdempotencyKeyMapper);
});

function idempotencyOwner(string $emailSuffix = 'idem'): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Idempotency Repo User',
        'email' => Str::uuid7().'@'.$emailSuffix.'.example.com',
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return UserId::fromString($userId);
}

function hex64(string $seed): string
{
    return hash('sha256', $seed);
}

describe('IdempotencyKeyRepository port', function () {
    it('exposes findActive, reserve, complete and deleteExpired', function () {
        $methods = collect((new ReflectionClass(IdempotencyKeyRepository::class))->getMethods())
            ->map(fn (ReflectionMethod $m) => $m->getName())
            ->sort()
            ->values()
            ->all();

        expect($methods)->toBe([
            'complete',
            'deleteExpired',
            'deleteExpiredForKey',
            'findActive',
            'findNonExpired',
            'reserve',
        ]);
    });
});

describe('idempotency_keys schema contract', function () {
    it('has the correct columns, types and constraints', function () {
        $columns = DB::select(
            "SELECT column_name, data_type, character_maximum_length, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'idempotency_keys'
             ORDER BY ordinal_position"
        );

        $columnMap = collect($columns)->keyBy('column_name');

        expect($columnMap->keys()->all())->toBe([
            'user_id',
            'key_hash',
            'request_fingerprint',
            'response_snapshot',
            'key_id',
            'created_at',
            'expires_at',
        ])
            ->and($columnMap->get('user_id')->data_type)->toBe('uuid')
            ->and($columnMap->get('key_hash')->data_type)->toBe('character')
            ->and($columnMap->get('key_hash')->character_maximum_length)->toBe(64)
            ->and($columnMap->get('request_fingerprint')->data_type)->toBe('character')
            ->and($columnMap->get('request_fingerprint')->character_maximum_length)->toBe(64)
            ->and($columnMap->get('response_snapshot')->data_type)->toBe('bytea')
            ->and($columnMap->get('key_id')->data_type)->toBe('character varying')
            ->and($columnMap->get('created_at')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('expires_at')->data_type)->toBe('timestamp with time zone');

        $columnNames = $columnMap->keys()->all();
        expect($columnNames)->not->toContain('key')
            ->and($columnNames)->not->toContain('idempotency_key')
            ->and($columnNames)->not->toContain('raw_key');
    });

    it('has a unique primary key on (user_id, key_hash) and an expires_at index', function () {
        $pkColumns = collect(DB::select(
            "SELECT a.attname
             FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             WHERE i.indrelid = 'idempotency_keys'::regclass AND i.indisprimary
             ORDER BY array_position(i.indkey, a.attnum)"
        ))->pluck('attname')->all();

        expect($pkColumns)->toBe(['user_id', 'key_hash']);

        $index = DB::selectOne(
            "SELECT indexname FROM pg_indexes
             WHERE tablename = 'idempotency_keys' AND indexname = 'idempotency_keys_expires_at_index'"
        );

        expect($index)->not->toBeNull()
            ->and($index->indexname)->toBe('idempotency_keys_expires_at_index');
    });

    it('runs exclusively against fake_link_testing', function () {
        expect((string) config('database.connections.pgsql.database'))->toBe('fake_link_testing');
    });
});

describe('EloquentIdempotencyKeyRepository', function () {
    it('reserves, completes and finds an active record with opaque snapshot bytes', function () {
        $owner = idempotencyOwner();
        $keyHash = hex64('key-a');
        $fingerprint = hex64('fp-a');
        $createdAt = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $expiresAt = $createdAt->modify('+24 hours');
        $snapshot = random_bytes(48);
        $keyId = 'idem-dev-1';

        $this->repo->reserve($owner, $keyHash, $fingerprint, $createdAt, $expiresAt);
        $this->repo->complete($owner, $keyHash, $snapshot, $keyId);

        $found = $this->repo->findActive($owner, $keyHash, $createdAt->modify('+1 hour'));

        expect($found)->not->toBeNull()
            ->and($found->userId->equals($owner))->toBeTrue()
            ->and($found->keyHash)->toBe($keyHash)
            ->and($found->requestFingerprint)->toBe($fingerprint)
            ->and($found->responseSnapshot)->toBe($snapshot)
            ->and($found->keyId)->toBe($keyId)
            ->and($found->expiresAt->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-19T12:00:00Z');

        $raw = DB::selectOne(
            'SELECT octet_length(response_snapshot) AS snap_len,
                    encode(response_snapshot, \'hex\') AS snap_hex
             FROM idempotency_keys WHERE user_id = ? AND key_hash = ?',
            [$owner->value(), $keyHash],
        );

        // Opaque bytea round-trip: length and hex must match the binary envelope.
        expect($raw)->not->toBeNull()
            ->and((int) $raw->snap_len)->toBe(strlen($snapshot))
            ->and($raw->snap_hex)->toBe(bin2hex($snapshot));
    });

    it('scopes uniqueness per user — same key_hash allowed for different users', function () {
        $userA = idempotencyOwner('a');
        $userB = idempotencyOwner('b');
        $keyHash = hex64('shared-key');
        $createdAt = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $expiresAt = $createdAt->modify('+24 hours');
        $snapshot = random_bytes(32);

        $this->repo->reserve($userA, $keyHash, hex64('fp-a'), $createdAt, $expiresAt);
        $this->repo->complete($userA, $keyHash, $snapshot, 'k1');

        $this->repo->reserve($userB, $keyHash, hex64('fp-b'), $createdAt, $expiresAt);
        $this->repo->complete($userB, $keyHash, $snapshot, 'k1');

        expect($this->repo->findActive($userA, $keyHash, $createdAt))->not->toBeNull()
            ->and($this->repo->findActive($userB, $keyHash, $createdAt))->not->toBeNull()
            ->and(DB::table('idempotency_keys')->count())->toBe(2);
    });

    it('rejects duplicate (user_id, key_hash) via unique constraint', function () {
        $owner = idempotencyOwner();
        $keyHash = hex64('dup-key');
        $createdAt = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $expiresAt = $createdAt->modify('+24 hours');

        $this->repo->reserve($owner, $keyHash, hex64('fp-1'), $createdAt, $expiresAt);

        expect(fn () => $this->repo->reserve($owner, $keyHash, hex64('fp-2'), $createdAt, $expiresAt))
            ->toThrow(QueryException::class);
    });

    it('does not return expired or incomplete reservations from findActive', function () {
        $owner = idempotencyOwner();
        $keyHash = hex64('expired-key');
        $createdAt = new DateTimeImmutable('2026-09-17T12:00:00Z');
        $expiresAt = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');

        $this->repo->reserve($owner, $keyHash, hex64('fp'), $createdAt, $expiresAt);
        $this->repo->complete($owner, $keyHash, random_bytes(16), 'k1');

        expect($this->repo->findActive($owner, $keyHash, $now))->toBeNull();

        $incompleteHash = hex64('incomplete-key');
        $this->repo->reserve(
            $owner,
            $incompleteHash,
            hex64('fp-incomplete'),
            $now,
            $now->modify('+24 hours'),
        );

        expect($this->repo->findActive($owner, $incompleteHash, $now))->toBeNull();
    });

    it('preserves the caller transaction — rollback undoes reserve and complete', function () {
        $owner = idempotencyOwner();
        $keyHash = hex64('tx-key');
        $createdAt = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $expiresAt = $createdAt->modify('+24 hours');

        try {
            DB::transaction(function () use ($owner, $keyHash, $createdAt, $expiresAt): void {
                $this->repo->reserve($owner, $keyHash, hex64('fp-tx'), $createdAt, $expiresAt);
                $this->repo->complete($owner, $keyHash, random_bytes(24), 'k1');

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(DB::table('idempotency_keys')->where('key_hash', $keyHash)->exists())->toBeFalse()
            ->and($this->repo->findActive($owner, $keyHash, $createdAt))->toBeNull();
    });

    it('deleteExpired removes only expired rows up to the limit', function () {
        $owner = idempotencyOwner();
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');

        foreach (['e1', 'e2', 'alive'] as $seed) {
            $created = $now->modify('-25 hours');
            $expires = $seed === 'alive' ? $now->modify('+1 hour') : $now->modify('-1 hour');
            $hash = hex64($seed);
            $this->repo->reserve($owner, $hash, hex64('fp-'.$seed), $created, $expires);
            $this->repo->complete($owner, $hash, random_bytes(8), 'k1');
        }

        $deleted = $this->repo->deleteExpired($now, 1);

        expect($deleted)->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(2);

        $deletedAgain = $this->repo->deleteExpired($now, 10);

        expect($deletedAgain)->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1)
            ->and($this->repo->findActive($owner, hex64('alive'), $now))->not->toBeNull();
    });

    it('never persists a raw idempotency key column or value as key_hash', function () {
        $owner = idempotencyOwner();
        $rawKey = 'client-raw-idempotency-key-16';
        $keyHash = hex64($rawKey);
        $createdAt = new DateTimeImmutable('2026-09-18T12:00:00Z');

        $this->repo->reserve($owner, $keyHash, hex64('fp'), $createdAt, $createdAt->modify('+24 hours'));
        $this->repo->complete($owner, $keyHash, random_bytes(16), 'k1');

        $row = (array) DB::table('idempotency_keys')->where('user_id', $owner->value())->first();

        expect($row)->not->toHaveKey('key')
            ->and($row['key_hash'])->toBe($keyHash)
            ->and($row['key_hash'])->not->toBe($rawKey)
            ->and(implode('|', array_map(strval(...), $row)))->not->toContain($rawKey);
    });
});
