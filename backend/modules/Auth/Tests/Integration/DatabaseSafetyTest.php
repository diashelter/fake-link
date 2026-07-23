<?php

declare(strict_types=1);

use Modules\Auth\Tests\Support\DatabaseSafety;
use PHPUnit\Framework\AssertionFailedError;

describe('DatabaseSafety', function () {
    it('aborts integration tests when database is not fake_link_testing', function () {
        expect(fn () => DatabaseSafety::guardOrFail('fake_link'))
            ->toThrow(AssertionFailedError::class, 'Integration tests must use fake_link_testing, got "fake_link".');
    });

    it('allows integration tests when database is fake_link_testing', function () {
        DatabaseSafety::guardOrFail('fake_link_testing');

        expect(true)->toBeTrue();
    });
});
