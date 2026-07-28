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

    it('exposes password_reset purpose', function () {
        expect(EmailActionPurpose::PasswordReset->value)->toBe('password_reset');
    });

    it('defines absolute ttl of 1800 seconds for password reset', function () {
        expect(EmailActionPurpose::PasswordReset->absoluteTtlSeconds())->toBe(1800);
    });

    it('parses password_reset from string', function () {
        expect(EmailActionPurpose::fromString('password_reset'))->toBe(EmailActionPurpose::PasswordReset);
    });
});
