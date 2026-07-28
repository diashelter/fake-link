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

describe('Email action tokens schema contract', function () {
    it('defines the canonical email_action_tokens table with uuid primary key and required columns', function () {
        $columns = DB::select(
            "SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'email_action_tokens'
             ORDER BY ordinal_position"
        );

        $columnMap = collect($columns)->keyBy('column_name');

        expect($columnMap->keys()->all())->toEqual([
            'id',
            'user_id',
            'token_hash',
            'purpose',
            'expires_at',
            'used_at',
            'created_at',
        ])
            ->and($columnMap->get('id')->data_type)->toBe('uuid')
            ->and($columnMap->get('user_id')->data_type)->toBe('uuid')
            ->and($columnMap->get('token_hash')->data_type)->toBe('character')
            ->and($columnMap->get('used_at')->is_nullable)->toBe('YES')
            ->and($columnMap->get('created_at')->is_nullable)->toBe('NO');
    });

    it('enforces allowed purpose values through a check constraint', function () {
        $constraints = DB::select(
            "SELECT conname
             FROM pg_constraint
             WHERE conrelid = 'public.email_action_tokens'::regclass
               AND contype = 'c'"
        );

        expect(collect($constraints)->pluck('conname')->all())->toContain('email_action_tokens_purpose_check');
    });

    it('enforces unique token_hash, user_id index, and foreign key to users', function () {
        $indexes = DB::select(
            "SELECT indexname, indexdef
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = 'email_action_tokens'"
        );

        $indexNames = collect($indexes)->pluck('indexname')->all();

        expect($indexNames)->toContain('email_action_tokens_token_hash_unique')
            ->and($indexNames)->toContain('email_action_tokens_user_id_index');

        $foreignKeys = DB::select(
            "SELECT conname
             FROM pg_constraint
             WHERE conrelid = 'public.email_action_tokens'::regclass
               AND contype = 'f'"
        );

        expect(collect($foreignKeys)->pluck('conname')->all())->not->toBeEmpty();
    });
});
