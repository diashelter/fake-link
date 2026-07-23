<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

describe('Users schema contract', function () {
    it('defines the canonical users table with uuid primary key and required columns', function () {
        $columns = DB::select(
            "SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'users'
             ORDER BY ordinal_position"
        );

        $columnMap = collect($columns)->keyBy('column_name');

        expect($columnMap->keys()->all())->toEqual([
            'id',
            'name',
            'email',
            'password',
            'status',
            'email_verified_at',
            'terms_version',
            'terms_accepted_at',
            'created_at',
            'updated_at',
        ])
            ->and($columnMap->get('id')->data_type)->toBe('uuid')
            ->and($columnMap->get('terms_version')->is_nullable)->toBe('NO')
            ->and($columnMap->get('terms_accepted_at')->is_nullable)->toBe('NO')
            ->and($columnMap->has('remember_token'))->toBeFalse();
    });

    it('does not create legacy laravel session or password reset tables', function () {
        $legacyTables = DB::select(
            "SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = 'public'
               AND table_name IN ('password_reset_tokens', 'sessions')"
        );

        expect($legacyTables)->toBeEmpty();
    });

    it('enforces allowed user status values through a check constraint', function () {
        $constraints = DB::select(
            "SELECT conname
             FROM pg_constraint
             WHERE conrelid = 'public.users'::regclass
               AND contype = 'c'"
        );

        expect(collect($constraints)->pluck('conname')->all())->toContain('users_status_check');
    });
});
