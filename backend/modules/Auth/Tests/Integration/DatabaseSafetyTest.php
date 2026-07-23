<?php

declare(strict_types=1);

use Modules\Auth\Tests\Support\DatabaseSafety;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

describe('DatabaseSafety', function () {
    it('aborts integration tests when database is not fake_link_testing', function () {
        config(['database.connections.pgsql.database' => 'fake_link']);

        $guard = new class extends TestCase
        {
            use DatabaseSafety;

            public function __construct()
            {
                parent::__construct('database safety guard');
            }

            public function runGuard(): void
            {
                $this->assertTestingDatabaseIsIsolated();
            }
        };

        expect(fn () => $guard->runGuard())->toThrow(AssertionFailedError::class);
    });

    it('allows integration tests when database is fake_link_testing', function () {
        config(['database.connections.pgsql.database' => 'fake_link_testing']);

        $guard = new class extends TestCase
        {
            use DatabaseSafety;

            public function __construct()
            {
                parent::__construct('database safety guard');
            }

            public function runGuard(): void
            {
                $this->assertTestingDatabaseIsIsolated();
            }
        };

        $guard->runGuard();

        expect(true)->toBeTrue();
    });
});
