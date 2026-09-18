<?php

declare(strict_types=1);

use Modules\Links\Domain\ValueObjects\IdempotencyKey;
use Modules\Links\Exceptions\LinksDomainException;

describe('IdempotencyKey', function () {
    it('accepts exactly 16 allowlisted characters', function () {
        $key = IdempotencyKey::fromString(str_repeat('a', 16));

        expect($key->value())->toBe(str_repeat('a', 16));
    });

    it('accepts exactly 128 allowlisted characters including punctuation', function () {
        $raw = str_repeat('A', 120).'._:-xx';

        expect(IdempotencyKey::fromString($raw)->value())->toBe($raw);
    });

    it('rejects fewer than 16 characters', function () {
        IdempotencyKey::fromString(str_repeat('a', 15));
    })->throws(LinksDomainException::class);

    it('rejects more than 128 characters', function () {
        IdempotencyKey::fromString(str_repeat('a', 129));
    })->throws(LinksDomainException::class);

    it('rejects spaces, unicode, and percent signs', function (string $raw) {
        IdempotencyKey::fromString($raw);
    })->with([
        'space' => ['abcdefghijklmnop '],
        'newline' => ["abcdefghijklmnop\n"],
        'unicode' => ['abcdefghijklmnopá'],
        'percent' => ['abcdefghijklmnop%'],
    ])->throws(LinksDomainException::class);
});
