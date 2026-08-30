<?php

declare(strict_types=1);

use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\LinksDomainException;

describe('Slug', function () {
    it('accepts a simple lowercase slug', function () {
        $slug = Slug::fromString('abc-123');

        expect($slug->value())->toBe('abc-123');
    });

    it('accepts exactly 1 character', function () {
        $slug = Slug::fromString('a');

        expect($slug->value())->toBe('a');
    });

    it('accepts exactly 48 characters', function () {
        $value = str_repeat('a', 48);
        $slug = Slug::fromString($value);

        expect($slug->value())->toBe($value);
    });

    it('rejects 49 characters', function () {
        Slug::fromString(str_repeat('a', 49));
    })->throws(LinksDomainException::class);

    it('rejects empty string', function () {
        Slug::fromString('');
    })->throws(LinksDomainException::class);

    it('rejects uppercase letters', function () {
        Slug::fromString('ABC');
    })->throws(LinksDomainException::class, 'The provided slug is invalid.');

    it('rejects characters outside [a-z0-9-]', function () {
        Slug::fromString('hello_world');
    })->throws(LinksDomainException::class);

    it('accepts hyphen at start (alias rules are slice 2)', function () {
        // Leading/trailing hyphens and consecutive hyphens are allowed structurally in this slice.
        // Alias uniqueness and formatting constraints are enforced in slice 2.
        $slug = Slug::fromString('-abc');

        expect($slug->value())->toBe('-abc');
    });

    it('accepts hyphen at end', function () {
        $slug = Slug::fromString('abc-');

        expect($slug->value())->toBe('abc-');
    });

    it('accepts consecutive hyphens', function () {
        // Consecutive hyphens are structurally allowed; slice 2 enforces alias format.
        $slug = Slug::fromString('a--b');

        expect($slug->value())->toBe('a--b');
    });

    it('equals returns true for same value', function () {
        $a = Slug::fromString('my-slug');
        $b = Slug::fromString('my-slug');

        expect($a->equals($b))->toBeTrue();
    });

    it('equals returns false for different values', function () {
        $a = Slug::fromString('slug-a');
        $b = Slug::fromString('slug-b');

        expect($a->equals($b))->toBeFalse();
    });

    it('preserves value byte-for-byte without normalization', function () {
        $raw = 'abc-123';
        $slug = Slug::fromString($raw);

        expect($slug->value())->toBe($raw);
    });
});
