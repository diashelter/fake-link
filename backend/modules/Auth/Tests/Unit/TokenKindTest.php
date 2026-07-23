<?php

declare(strict_types=1);

use Modules\Auth\Domain\Enums\TokenKind;

describe('TokenKind', function () {
    it('exposes verification and session kinds', function () {
        expect(TokenKind::Verification->value)->toBe('verification')
            ->and(TokenKind::Session->value)->toBe('session');
    });

    it('defines absolute ttl of 24 hours for verification and 7 days for session', function () {
        expect(TokenKind::Verification->absoluteTtlSeconds())->toBe(86400)
            ->and(TokenKind::Session->absoluteTtlSeconds())->toBe(604800);
    });

    it('defines idle ttl of 1 hour for verification and 24 hours for session', function () {
        expect(TokenKind::Verification->idleTtlSeconds())->toBe(3600)
            ->and(TokenKind::Session->idleTtlSeconds())->toBe(86400);
    });
});
