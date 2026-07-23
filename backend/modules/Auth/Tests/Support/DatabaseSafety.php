<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Before;

trait DatabaseSafety
{
    #[Before]
    public function assertTestingDatabaseIsIsolated(): void
    {
        self::guardOrFail((string) config('database.connections.pgsql.database'));
    }

    public static function guardOrFail(string $database): void
    {
        if ($database !== 'fake_link_testing') {
            throw new AssertionFailedError(sprintf(
                'Integration tests must use fake_link_testing, got "%s".',
                $database,
            ));
        }
    }
}
