<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Modules\Links\DTOs\Output\IdempotencyLookup;
use Modules\Links\DTOs\Output\IdempotencyRecord;
use Modules\Links\Infrastructure\Console\Commands\PruneExpiredIdempotencyKeys;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\IdempotencyKeyMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentIdempotencyKeyRepository;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    $this->repo = new EloquentIdempotencyKeyRepository(new IdempotencyKeyMapper);
    Carbon::setTestNow();
    config(['links.idempotency.prune_batch_size' => 1000]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function pruneIdempotencyOwner(string $emailSuffix = 'prune'): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Prune Idempotency User',
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

function pruneHex64(string $seed): string
{
    return hash('sha256', $seed);
}

function seedIdempotencyRow(
    IdempotencyKeyRepository $repo,
    UserId $owner,
    string $seed,
    DateTimeImmutable $createdAt,
    DateTimeImmutable $expiresAt,
): void {
    $hash = pruneHex64($seed);
    $repo->reserve($owner, $hash, pruneHex64('fp-'.$seed), $createdAt, $expiresAt);
    $repo->complete($owner, $hash, random_bytes(16), 'k1');
}

describe('links:prune-idempotency', function () {
    it('is registered as an artisan command', function () {
        expect(Artisan::all())->toHaveKey('links:prune-idempotency')
            ->and(Artisan::all()['links:prune-idempotency'])->toBeInstanceOf(PruneExpiredIdempotencyKeys::class);
    });

    it('removes only rows with expires_at before, at, and not after the clock instant', function () {
        $owner = pruneIdempotencyOwner();
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');
        Carbon::setTestNow(Carbon::parse('2026-09-18T12:00:00Z'));

        seedIdempotencyRow($this->repo, $owner, 'before', $now->modify('-25 hours'), $now->modify('-1 second'));
        seedIdempotencyRow($this->repo, $owner, 'at', $now->modify('-24 hours'), $now);
        seedIdempotencyRow($this->repo, $owner, 'after', $now->modify('-1 hour'), $now->modify('+1 second'));

        $this->artisan('links:prune-idempotency')->assertSuccessful();

        $remaining = DB::table('idempotency_keys')->pluck('key_hash')->all();

        expect($remaining)->toBe([pruneHex64('after')])
            ->and(DB::table('idempotency_keys')->count())->toBe(1);
    });

    it('respects the configured batch size', function () {
        $owner = pruneIdempotencyOwner('batch');
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');
        Carbon::setTestNow(Carbon::parse('2026-09-18T12:00:00Z'));
        config(['links.idempotency.prune_batch_size' => 1]);

        seedIdempotencyRow($this->repo, $owner, 'e1', $now->modify('-25 hours'), $now->modify('-1 hour'));
        seedIdempotencyRow($this->repo, $owner, 'e2', $now->modify('-25 hours'), $now->modify('-1 hour'));
        seedIdempotencyRow($this->repo, $owner, 'alive', $now->modify('-1 hour'), $now->modify('+1 hour'));

        $this->artisan('links:prune-idempotency')->assertSuccessful();

        expect(DB::table('idempotency_keys')->count())->toBe(2);

        $this->artisan('links:prune-idempotency')->assertSuccessful();

        expect(DB::table('idempotency_keys')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->where('key_hash', pruneHex64('alive'))->exists())->toBeTrue();
    });

    it('leaves the same final state when run twice', function () {
        $owner = pruneIdempotencyOwner('twice');
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');
        Carbon::setTestNow(Carbon::parse('2026-09-18T12:00:00Z'));

        seedIdempotencyRow($this->repo, $owner, 'expired', $now->modify('-25 hours'), $now->modify('-1 hour'));
        seedIdempotencyRow($this->repo, $owner, 'valid', $now->modify('-1 hour'), $now->modify('+12 hours'));

        $this->artisan('links:prune-idempotency')->assertSuccessful();
        $afterFirst = DB::table('idempotency_keys')->orderBy('key_hash')->pluck('key_hash')->all();

        $this->artisan('links:prune-idempotency')->assertSuccessful();
        $afterSecond = DB::table('idempotency_keys')->orderBy('key_hash')->pluck('key_hash')->all();

        expect($afterSecond)->toBe($afterFirst)
            ->and($afterFirst)->toBe([pruneHex64('valid')]);
    });

    it('does not delete valid keys when prune fails', function () {
        $owner = pruneIdempotencyOwner('fail');
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');
        Carbon::setTestNow(Carbon::parse('2026-09-18T12:00:00Z'));

        seedIdempotencyRow($this->repo, $owner, 'valid', $now->modify('-1 hour'), $now->modify('+12 hours'));
        seedIdempotencyRow($this->repo, $owner, 'expired', $now->modify('-25 hours'), $now->modify('-1 hour'));

        $failing = new class implements IdempotencyKeyRepository
        {
            public function findActive(UserId $userId, string $keyHash, DateTimeImmutable $now): ?IdempotencyRecord
            {
                throw new RuntimeException('not used');
            }

            public function findNonExpired(UserId $userId, string $keyHash, DateTimeImmutable $now): ?IdempotencyLookup
            {
                throw new RuntimeException('not used');
            }

            public function reserve(
                UserId $userId,
                string $keyHash,
                string $requestFingerprint,
                DateTimeImmutable $createdAt,
                DateTimeImmutable $expiresAt,
            ): void {
                throw new RuntimeException('not used');
            }

            public function complete(
                UserId $userId,
                string $keyHash,
                string $responseSnapshot,
                string $keyId,
            ): void {
                throw new RuntimeException('not used');
            }

            public function deleteExpiredForKey(UserId $userId, string $keyHash, DateTimeImmutable $now): void
            {
                throw new RuntimeException('not used');
            }

            public function deleteExpired(DateTimeImmutable $now, int $limit): int
            {
                throw new RuntimeException('simulated prune failure with secret=super-secret-token');
            }
        };

        $this->app->instance(IdempotencyKeyRepository::class, $failing);

        /** @var list<MessageLogged> $logs */
        $logs = [];
        Log::listen(function (MessageLogged $event) use (&$logs): void {
            $logs[] = $event;
        });

        $this->artisan('links:prune-idempotency')->assertFailed();

        $cleanupSignals = array_values(array_filter(
            $logs,
            fn (MessageLogged $event): bool => $event->message === 'links.idempotency.cleanup_failed',
        ));

        expect($cleanupSignals)->toHaveCount(1)
            ->and($cleanupSignals[0]->level)->toBe('warning')
            ->and($cleanupSignals[0]->context)->toBe(['operation' => 'prune'])
            ->and(json_encode($cleanupSignals[0]->context, JSON_THROW_ON_ERROR))->not->toContain('super-secret-token')
            ->and(json_encode($cleanupSignals[0]->context, JSON_THROW_ON_ERROR))->not->toContain('simulated prune failure')
            ->and(DB::table('idempotency_keys')->count())->toBe(2)
            ->and(DB::table('idempotency_keys')->where('key_hash', pruneHex64('valid'))->exists())->toBeTrue()
            ->and(DB::table('idempotency_keys')->where('key_hash', pruneHex64('expired'))->exists())->toBeTrue();
    });
});

describe('idempotency prune schedule', function () {
    it('runs every minute without overlapping', function () {
        // Ensure routes/console.php has been required so Schedule::command() ran.
        Artisan::all();

        $event = collect(app(Schedule::class)->events())
            ->first(function ($event): bool {
                $command = $event->command ?? $event->description ?? '';

                return str_contains($command, 'links:prune-idempotency');
            });

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *')
            ->and($event->withoutOverlapping)->toBeTrue();
    });
});
