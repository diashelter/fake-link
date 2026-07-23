<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use PHPUnit\Framework\Attributes\Before;

trait DatabaseSafety
{
    #[Before]
    public function assertTestingDatabaseIsIsolated(): void
    {
        $database = (string) config('database.connections.pgsql.database');

        if ($database !== 'fake_link_testing') {
            $this->fail(sprintf(
                'Integration tests must use fake_link_testing, got "%s".',
                $database,
            ));
        }
    }
}
