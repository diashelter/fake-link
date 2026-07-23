<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

uses(TestCase::class);

describe('DatabaseSafety', function () {
    it('boots the laravel testing application', function () {
        expect(config('database.connections.pgsql.database'))->toBe('fake_link_testing');
    });

    it('connects to a dedicated postgres testing database that exists', function () {
        $databaseName = config('database.connections.pgsql.database');

        expect($databaseName)->toBe('fake_link_testing')
            ->and($databaseName)->not->toBe('fake_link');

        $result = DB::selectOne(
            'SELECT datname FROM pg_database WHERE datname = ?',
            [$databaseName],
        );

        expect($result)->not->toBeNull()
            ->and($result->datname)->toBe('fake_link_testing');
    });

    it('aborts integration tests when database is not fake_link_testing', function () {
        expect(fn () => DatabaseSafetyGuard::assertIsolated('fake_link'))
            ->toThrow(AssertionFailedError::class, 'Integration tests must use fake_link_testing, got "fake_link".');
    });

    it('allows integration tests when database is fake_link_testing', function () {
        DatabaseSafetyGuard::assertIsolated('fake_link_testing');

        expect(true)->toBeTrue();
    });
});
