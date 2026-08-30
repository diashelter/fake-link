<?php

declare(strict_types=1);

use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Exceptions\LinksDomainException;

describe('ShortLinkId', function () {
    it('accepts a valid uuid v7', function () {
        $id = ShortLinkId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');

        expect($id->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });

    it('rejects uuid v4', function () {
        ShortLinkId::fromString('550e8400-e29b-41d4-a716-446655440000');
    })->throws(LinksDomainException::class, 'The provided short link identifier is invalid.');

    it('rejects empty string', function () {
        ShortLinkId::fromString('');
    })->throws(LinksDomainException::class);

    it('rejects malformed string', function () {
        ShortLinkId::fromString('not-a-uuid');
    })->throws(LinksDomainException::class);

    it('normalizes uuid to lowercase', function () {
        $id = ShortLinkId::fromString('018E8B8A-7B6A-7000-8000-123456789ABC');

        expect($id->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });

    it('equals returns true for same value', function () {
        $a = ShortLinkId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');
        $b = ShortLinkId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');

        expect($a->equals($b))->toBeTrue();
    });

    it('equals returns false for different values', function () {
        $a = ShortLinkId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');
        $b = ShortLinkId::fromString('018e8b8a-7b6a-7001-8000-123456789abc');

        expect($a->equals($b))->toBeFalse();
    });
});
