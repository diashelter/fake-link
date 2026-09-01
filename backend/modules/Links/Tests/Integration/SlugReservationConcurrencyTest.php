<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Concurrent reservation of case-equivalent aliases (SLG-17, SLG-18)
|--------------------------------------------------------------------------
|
| Why this file does NOT use a transactional RefreshDatabase for the race:
| RefreshDatabase wraps the default connection in a single transaction, so a
| "commit" on it is only a SAVEPOINT release — a second connection would never
| see the row and the primary-key collision under test could not happen. The
| race is therefore run on two dedicated real connections (pgsql_winner /
| pgsql_loser) that commit for real, and the rows they leave behind are
| removed explicitly in afterEach. RefreshDatabase is still applied so the
| schema exists and the default connection stays clean.
|
| Discrimination sensor: if EloquentSlugReservationRepository::reserve stopped
| catching UniqueConstraintViolationException (raw QueryException leaks) the
| "losing writer receives SlugUnavailable" test fails; if SlugUnavailable
| gained an owner/reserved_at accessor or a non-uniform message the "carries
| no occupant data" test fails; if the slug primary key were dropped the
| "exactly one row" test fails.
|
*/

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $base = config('database.connections.pgsql');

    DatabaseSafetyGuard::assertIsolated((string) $base['database']);

    config([
        'database.connections.pgsql_winner' => $base,
        'database.connections.pgsql_loser' => $base,
    ]);

    $this->winner = DB::connection('pgsql_winner');
    $this->loser = DB::connection('pgsql_loser');
});

afterEach(function () {
    // The winner connection commits real rows; scrub them so the next test
    // and the next run start clean.
    $this->winner->table('short_links')->where('slug', 'foo')->delete();
    $this->winner->table('slug_reservations')->where('slug', 'foo')->delete();
    $this->winner->table('users')->where('email', 'like', 'concurrency-%@example.com')->delete();

    DB::purge('pgsql_winner');
    DB::purge('pgsql_loser');
});

function seedUserOn(ConnectionInterface $conn): string
{
    $id = (string) Str::uuid7();

    $conn->table('users')->insert([
        'id' => $id,
        'name' => 'Concurrency User',
        'email' => 'concurrency-'.$id.'@example.com',
        'password' => 'hash',
        'status' => 'pending_verification',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

describe('concurrent reservation of case-equivalent aliases', function () {
    it('lets the database primary key decide the winner between two overlapping transactions', function () {
        $this->winner->beginTransaction();
        $this->loser->beginTransaction();          // transactions now overlap

        // 'Foo' and 'foo' both normalize to the same slug value.
        $winningSlug = Slug::fromCustomAlias('Foo');
        $losingSlug = Slug::fromCustomAlias('foo');

        $this->winner->table('slug_reservations')->insert([
            'slug' => $winningSlug->value(),
            'reserved_at' => now(),
        ]);
        $this->winner->commit();

        $caught = null;

        try {
            $this->loser->table('slug_reservations')->insert([
                'slug' => $losingSlug->value(),
                'reserved_at' => now(),
            ]);
            $this->loser->commit();
        } catch (UniqueConstraintViolationException $e) {
            $this->loser->rollBack();
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(UniqueConstraintViolationException::class);
    });

    it('gives the losing writer a SlugUnavailable through the adapter, not a raw database error', function () {
        $this->winner->table('slug_reservations')->insert([
            'slug' => 'foo',
            'reserved_at' => now(),
        ]);
        $this->winner->commit();

        $threw = null;

        try {
            // The default connection now races against the already-committed
            // winner; a SAVEPOINT keeps the surrounding transaction usable.
            DB::transaction(fn () => (new EloquentSlugReservationRepository)->reserve(Slug::fromCustomAlias('FOO')));
        } catch (Throwable $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(SlugUnavailable::class);
    });

    it('ends with exactly one slug_reservations row and no short_links duplicate', function () {
        $this->winner->beginTransaction();
        $this->loser->beginTransaction();

        $this->winner->table('slug_reservations')->insert(['slug' => 'foo', 'reserved_at' => now()]);
        $this->winner->commit();

        try {
            $this->loser->table('slug_reservations')->insert(['slug' => 'foo', 'reserved_at' => now()]);
            $this->loser->commit();
        } catch (UniqueConstraintViolationException) {
            $this->loser->rollBack();
        }

        $reservationRows = (int) $this->winner->table('slug_reservations')->where('slug', 'foo')->count();
        $linkRows = (int) $this->winner->table('short_links')->where('slug', 'foo')->count();

        expect($reservationRows)->toBe(1)
            ->and($linkRows)->toBe(0);
    });

    it('reports the same occupant-free failure whether the winning slug is an orphan reservation or backed by a link', function () {
        $reflection = new ReflectionClass(SlugUnavailable::class);
        $ownPublicApi = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === SlugUnavailable::class,
            ),
        );
        sort($ownPublicApi);

        // The only things SlugUnavailable adds are a factory and its error code
        // — no accessor that could leak the occupant (owner, reserved_at, ...).
        expect($ownPublicApi)->toBe(['errorCode', 'reserved']);

        // Orphan reservation occupies 'foo'.
        $this->winner->table('slug_reservations')->insert(['slug' => 'foo', 'reserved_at' => now()]);
        $this->winner->commit();

        $orphanMessage = null;
        try {
            DB::transaction(fn () => (new EloquentSlugReservationRepository)->reserve(Slug::fromCustomAlias('foo')));
        } catch (SlugUnavailable $e) {
            $orphanMessage = $e->getMessage();
        }

        // Now the same slug is also backed by a live short link.
        $userId = seedUserOn($this->winner);
        $this->winner->table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => 'foo',
            'slug_source' => 'custom',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linkedMessage = null;
        try {
            DB::transaction(fn () => (new EloquentSlugReservationRepository)->reserve(Slug::fromCustomAlias('foo')));
        } catch (SlugUnavailable $e) {
            $linkedMessage = $e->getMessage();
        }

        expect($orphanMessage)->toBe('The requested slug is unavailable.')
            ->and($linkedMessage)->toBe($orphanMessage);
    });
});
