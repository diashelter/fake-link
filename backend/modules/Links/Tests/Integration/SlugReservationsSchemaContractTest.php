<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

describe('slug_reservations schema contract', function () {
    it('creates slug varchar(48) primary key and reserved_at timestamptz columns', function () {
        $columns = DB::select(
            "SELECT column_name, data_type, character_maximum_length, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'slug_reservations'
             ORDER BY ordinal_position"
        );

        $columnMap = collect($columns)->keyBy('column_name');

        expect($columnMap->keys()->sort()->values()->all())->toEqual(['reserved_at', 'slug'])
            ->and($columnMap->get('slug')->data_type)->toBe('character varying')
            ->and((int) $columnMap->get('slug')->character_maximum_length)->toBe(48)
            ->and($columnMap->get('slug')->is_nullable)->toBe('NO')
            ->and($columnMap->get('reserved_at')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('reserved_at')->is_nullable)->toBe('NO');
    });

    it('enforces primary key uniqueness on slug — duplicate insert fails', function () {
        SlugReservationModel::create(['slug' => 'my-slug', 'reserved_at' => now()]);

        expect(fn () => SlugReservationModel::create(['slug' => 'my-slug', 'reserved_at' => now()]))
            ->toThrow(QueryException::class);
    });

    it('allows distinct slugs to coexist', function () {
        SlugReservationModel::create(['slug' => 'slug-a', 'reserved_at' => now()]);
        SlugReservationModel::create(['slug' => 'slug-b', 'reserved_at' => now()]);

        expect(SlugReservationModel::count())->toBe(2);
    });

    it('has no created_at or updated_at columns', function () {
        $columns = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'slug_reservations'"
        );

        $names = collect($columns)->pluck('column_name')->all();

        expect($names)->not->toContain('created_at')
            ->and($names)->not->toContain('updated_at');
    });

    it('factory creates a valid slug reservation', function () {
        $reservation = SlugReservationModel::factory()->create();

        expect($reservation->slug)->not->toBeEmpty()
            ->and($reservation->reserved_at)->not->toBeNull();
    });
});
