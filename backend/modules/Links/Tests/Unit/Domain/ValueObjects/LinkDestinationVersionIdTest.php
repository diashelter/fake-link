<?php

declare(strict_types=1);

use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;
use Modules\Links\Exceptions\LinksDomainException;

describe('LinkDestinationVersionId', function () {
    it('accepts a valid uuid v7', function () {
        $id = LinkDestinationVersionId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');

        expect($id->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });

    it('rejects uuid v4', function () {
        LinkDestinationVersionId::fromString('550e8400-e29b-41d4-a716-446655440000');
    })->throws(LinksDomainException::class, 'The provided link destination version identifier is invalid.');

    it('rejects empty string', function () {
        LinkDestinationVersionId::fromString('');
    })->throws(LinksDomainException::class);

    it('rejects malformed string', function () {
        LinkDestinationVersionId::fromString('not-a-uuid');
    })->throws(LinksDomainException::class);

    it('normalizes uuid to lowercase', function () {
        $id = LinkDestinationVersionId::fromString('018E8B8A-7B6A-7000-8000-123456789ABC');

        expect($id->value())->toBe('018e8b8a-7b6a-7000-8000-123456789abc');
    });

    it('equals returns true for same value', function () {
        $a = LinkDestinationVersionId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');
        $b = LinkDestinationVersionId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');

        expect($a->equals($b))->toBeTrue();
    });

    it('equals returns false for different values', function () {
        $a = LinkDestinationVersionId::fromString('018e8b8a-7b6a-7000-8000-123456789abc');
        $b = LinkDestinationVersionId::fromString('018e8b8a-7b6a-7001-8000-123456789abc');

        expect($a->equals($b))->toBeFalse();
    });
});
