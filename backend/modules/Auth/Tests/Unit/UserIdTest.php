<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Exceptions\AuthDomainException;

describe('UserId', function () {
    it('accepts a valid uuid v7', function () {
        $userId = UserId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');

        expect($userId->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });

    it('rejects uuid v4', function () {
        UserId::fromString('550e8400-e29b-41d4-a716-446655440000');
    })->throws(AuthDomainException::class, 'The provided user identifier is invalid.');

    it('rejects malformed uuid strings', function () {
        UserId::fromString('not-a-uuid');
    })->throws(AuthDomainException::class);

    it('normalizes uuid to lowercase', function () {
        $userId = UserId::fromString('018E8B8A-7B6A-7000-8000-123456789ABC');

        expect($userId->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });
});
