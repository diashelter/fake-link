<?php

declare(strict_types=1);

use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

uses(TestCase::class);

describe('DatabaseSafety', function () {
    it('boots the laravel testing application', function () {
        expect(config('database.connections.pgsql.database'))->toBe('fake_link_testing');
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
