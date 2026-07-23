<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

describe('Hashing config', function () {
    it('defaults to argon2id with at least 64 mib memory cost', function () {
        expect(config('hashing.driver'))->toBe('argon2id')
            ->and(config('hashing.argon.memory'))->toBeGreaterThanOrEqual(65536);
    });
});
