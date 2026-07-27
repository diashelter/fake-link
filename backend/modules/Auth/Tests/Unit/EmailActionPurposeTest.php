<?php

declare(strict_types=1);

use Modules\Auth\Domain\Enums\EmailActionPurpose;

describe('EmailActionPurpose', function () {
    it('exposes email_verification purpose', function () {
        expect(EmailActionPurpose::EmailVerification->value)->toBe('email_verification');
    });

    it('defines absolute ttl of 3600 seconds for email verification', function () {
        expect(EmailActionPurpose::EmailVerification->absoluteTtlSeconds())->toBe(3600);
    });
});
