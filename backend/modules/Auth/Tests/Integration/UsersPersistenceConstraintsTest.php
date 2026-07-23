<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\AuthDomainException;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

describe('Users persistence constraints', function () {
    it('rejects rows missing required terms fields', function () {
        expect(fn () => UserModel::query()->create([
            'id' => (string) Str::uuid7(),
            'name' => 'Jane Doe',
            'email' => 'missing-terms@example.com',
            'password' => 'hash',
            'status' => 'pending_verification',
        ]))->toThrow(QueryException::class);
    });

    it('rejects invalid status values at the database layer', function () {
        expect(fn () => UserModel::query()->create([
            'id' => (string) Str::uuid7(),
            'name' => 'Jane Doe',
            'email' => 'invalid-status@example.com',
            'password' => 'hash',
            'status' => 'invalid_status',
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('rejects uuid v4 identities before repository persistence', function () {
        expect(fn () => UserId::fromString('550e8400-e29b-41d4-a716-446655440000'))
            ->toThrow(AuthDomainException::class);

        /** @var Builder<UserModel> $query */
        $query = UserModel::query();

        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder exists())
        expect($query->exists())->toBeFalse();
    });
});
