<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Exceptions\AuthDomainException;

describe('AuthTokenId', function () {
    it('accepts a valid uuid v7', function () {
        $tokenId = AuthTokenId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');

        expect($tokenId->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });

    it('rejects uuid v4', function () {
        AuthTokenId::fromString('550e8400-e29b-41d4-a716-446655440000');
    })->throws(AuthDomainException::class, 'The provided auth token identifier is invalid.');

    it('rejects malformed uuid strings', function () {
        AuthTokenId::fromString('not-a-uuid');
    })->throws(AuthDomainException::class);

    it('normalizes uuid to lowercase', function () {
        $tokenId = AuthTokenId::fromString('018E8B8A-7B6A-7000-8000-123456789ABC');

        expect($tokenId->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });
});
