<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    $this->repo = new EloquentSlugReservationRepository;
});

function makeUserRow(): string
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Repo Test User',
        'email' => Str::uuid7().'@example.com',
        'password' => 'hash',
        'status' => 'pending_verification',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $userId;
}

describe('SlugReservationRepository port', function () {
    it('exposes only reserve and existsWithoutLink — no removal or update path', function () {
        $methods = collect((new ReflectionClass(SlugReservationRepository::class))->getMethods())
            ->map(fn (ReflectionMethod $m) => $m->getName())
            ->sort()
            ->values()
            ->all();

        expect($methods)->toBe(['existsWithoutLink', 'reserve']);
    });
});

describe('EloquentSlugReservationRepository::reserve', function () {
    it('inserts a reservation row for the slug', function () {
        $this->repo->reserve(Slug::fromCustomAlias('my-slug'));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->whereKey('my-slug')->exists())->toBeTrue();
    });

    it('inserts without a prior availability SELECT against slug_reservations (SLG-06)', function () {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->repo->reserve(Slug::fromCustomAlias('no-select'));

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower(trim($q)));

        $selectsOnTable = $queries->filter(
            fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'slug_reservations')
        );

        expect($selectsOnTable)->toBeEmpty()
            ->and($queries->contains(fn ($q) => str_starts_with($q, 'insert into "slug_reservations"')))->toBeTrue();
    });

    it('translates a primary-key collision into SlugUnavailable', function () {
        $this->repo->reserve(Slug::fromCustomAlias('taken-slug'));

        expect(fn () => $this->repo->reserve(Slug::fromCustomAlias('taken-slug')))
            ->toThrow(SlugUnavailable::class);
    });

    it('leaves the existing reservation untouched after a rejected duplicate', function () {
        $this->repo->reserve(Slug::fromCustomAlias('keep-slug'));
        $original = SlugReservationModel::findOrFail('keep-slug');

        try {
            // Nested transaction = SAVEPOINT: the collision rolls back to the
            // savepoint so the surrounding transaction stays usable for the
            // assertions below (this is the retry shape link-creation uses).
            DB::transaction(fn () => $this->repo->reserve(Slug::fromCustomAlias('keep-slug')));
        } catch (SlugUnavailable) {
            // expected
        }

        $after = SlugReservationModel::findOrFail('keep-slug');

        // @phpstan-ignore staticMethod.dynamicCall
        $count = SlugReservationModel::query()->where('slug', 'keep-slug')->count();

        expect($count)->toBe(1)
            ->and($after->reserved_at->equalTo($original->reserved_at))->toBeTrue();
    });

    it('participates in the caller transaction — a rollback discards the reservation (SLG-14)', function () {
        try {
            DB::transaction(function () {
                $this->repo->reserve(Slug::fromCustomAlias('rolled-back'));

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->whereKey('rolled-back')->exists())->toBeFalse();
    });

    it('lets a non-unique database failure propagate as QueryException, never as SlugUnavailable', function () {
        // Hide the table so the INSERT fails with SQLSTATE 42P01 (undefined
        // table) — a generic QueryException, not a unique violation. The
        // RENAME is undone by RefreshDatabase's transaction rollback.
        DB::statement('ALTER TABLE slug_reservations RENAME TO slug_reservations_hidden');

        $threw = null;

        try {
            $this->repo->reserve(Slug::fromCustomAlias('any-slug'));
        } catch (Throwable $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(QueryException::class)
            ->and($threw)->not->toBeInstanceOf(SlugUnavailable::class);
    });
});

describe('EloquentSlugReservationRepository::existsWithoutLink', function () {
    it('is true for a reservation with no short link (orphan)', function () {
        $this->repo->reserve(Slug::fromCustomAlias('orphan-one'));

        expect($this->repo->existsWithoutLink(Slug::fromCustomAlias('orphan-one')))->toBeTrue();
    });

    it('is false when a short link references the slug', function () {
        $userId = makeUserRow();
        $link = ShortLinkModel::factory()->withUserId($userId)->create();

        expect($this->repo->existsWithoutLink(Slug::fromGenerated($link->slug)))->toBeFalse();
    });

    it('is false when no reservation exists at all', function () {
        expect($this->repo->existsWithoutLink(Slug::fromCustomAlias('never-reserved')))->toBeFalse();
    });
});
