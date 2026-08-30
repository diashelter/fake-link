<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Infrastructure\Persistence\Eloquent\Factories\ShortLinkModelFactory;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

describe('short_links schema contract', function () {
    it('has the correct columns with correct types', function () {
        $columns = DB::select(
            "SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'short_links'
             ORDER BY ordinal_position"
        );

        $columnMap = collect($columns)->keyBy('column_name');

        expect($columnMap->get('id')->data_type)->toBe('uuid')
            ->and($columnMap->get('user_id')->data_type)->toBe('uuid')
            ->and($columnMap->get('slug')->data_type)->toBe('character varying')
            ->and($columnMap->get('slug_source')->data_type)->toBe('text')
            ->and($columnMap->get('title')->data_type)->toBe('character varying')
            ->and($columnMap->get('title')->is_nullable)->toBe('YES')
            ->and($columnMap->get('is_enabled')->data_type)->toBe('boolean')
            ->and($columnMap->get('blocked_at')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('blocked_at')->is_nullable)->toBe('YES')
            ->and($columnMap->get('expires_at')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('expires_at')->is_nullable)->toBe('YES')
            ->and($columnMap->get('version')->data_type)->toBe('bigint')
            ->and($columnMap->get('created_at')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('updated_at')->data_type)->toBe('timestamp with time zone');
    });

    it('has no status or effective_status column', function () {
        $columns = DB::select(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'short_links'"
        );

        $names = collect($columns)->pluck('column_name')->all();

        expect($names)->not->toContain('status')
            ->and($names)->not->toContain('effective_status');
    });

    it('rejects insert of slug without a matching slug_reservation (FK violation)', function () {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'user-fk-test@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => 'no-reservation-slug',
            'slug_source' => 'automatic',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('restricts deletion of slug_reservation referenced by short_link', function () {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'user-slug-restrict@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservation = SlugReservationModel::create(['slug' => 'restricted-slug', 'reserved_at' => now()]);

        DB::table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => $reservation->slug,
            'slug_source' => 'automatic',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => $reservation->delete())->toThrow(QueryException::class);
    });

    it('restricts deletion of user referenced by short_link', function () {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'user-restrict@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservation = SlugReservationModel::create(['slug' => 'user-ref-slug', 'reserved_at' => now()]);

        DB::table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => $reservation->slug,
            'slug_source' => 'automatic',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('users')->where('id', $userId)->delete())->toThrow(QueryException::class);
    });

    it('enforces uniqueness of slug across short_links', function () {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'user-unique-slug@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservation = SlugReservationModel::create(['slug' => 'unique-slug', 'reserved_at' => now()]);

        DB::table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => $reservation->slug,
            'slug_source' => 'automatic',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn () => DB::table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => $reservation->slug,
            'slug_source' => 'automatic',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('rejects slug_source outside the allowed set', function () {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'user-check@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservation = SlugReservationModel::create(['slug' => 'check-slug', 'reserved_at' => now()]);

        expect(fn () => DB::table('short_links')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'slug' => $reservation->slug,
            'slug_source' => 'invalid_source',
            'is_enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('factory creates a record with uuid v7 id and version 1', function () {
        $userId = (string) Str::uuid7();
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Factory Test User',
            'email' => 'factory-user@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $link = ShortLinkModel::factory()->withUserId($userId)->create();

        expect($link->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i')
            ->and($link->version)->toBe(1);
    });
});
