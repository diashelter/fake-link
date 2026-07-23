<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

final class DatabaseSafetyGuard
{
    public static function assertIsolated(string $database): void
    {
        if ($database !== 'fake_link_testing') {
            throw new AssertionFailedError(sprintf(
                'Integration tests must use fake_link_testing, got "%s".',
                $database,
            ));
        }
    }
}
