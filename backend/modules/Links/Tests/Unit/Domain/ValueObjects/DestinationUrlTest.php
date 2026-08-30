<?php

declare(strict_types=1);

use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Exceptions\LinksDomainException;

describe('DestinationUrl', function () {
    it('accepts a valid http url', function () {
        $url = DestinationUrl::fromString('http://example.com');

        expect($url->value())->toBe('http://example.com');
    });

    it('accepts a valid https url', function () {
        $url = DestinationUrl::fromString('https://example.com/path');

        expect($url->value())->toBe('https://example.com/path');
    });

    it('rejects ftp scheme', function () {
        DestinationUrl::fromString('ftp://example.com');
    })->throws(LinksDomainException::class, 'The provided destination URL is invalid.');

    it('rejects javascript scheme', function () {
        DestinationUrl::fromString('javascript:alert(1)');
    })->throws(LinksDomainException::class);

    it('rejects data scheme', function () {
        DestinationUrl::fromString('data:text/html,<h1>hi</h1>');
    })->throws(LinksDomainException::class);

    it('rejects string without scheme', function () {
        DestinationUrl::fromString('example.com/path');
    })->throws(LinksDomainException::class);

    it('rejects url with empty host', function () {
        DestinationUrl::fromString('https:///path');
    })->throws(LinksDomainException::class);

    it('accepts exactly 2048 characters', function () {
        $base = 'https://example.com/';
        $padding = str_repeat('a', 2048 - strlen($base));
        $raw = $base.$padding;

        expect(strlen($raw))->toBe(2048);

        $url = DestinationUrl::fromString($raw);

        expect($url->value())->toBe($raw);
    });

    it('rejects 2049 characters', function () {
        $base = 'https://example.com/';
        $padding = str_repeat('a', 2049 - strlen($base));
        $raw = $base.$padding;

        expect(strlen($raw))->toBe(2049);

        DestinationUrl::fromString($raw);
    })->throws(LinksDomainException::class);

    it('preserves query string and fragment without alteration', function () {
        $raw = 'https://example.com/path?a=1&b=2#frag';
        $url = DestinationUrl::fromString($raw);

        expect($url->value())->toBe($raw);
    });

    it('preserves percent-encoding without alteration', function () {
        $raw = 'https://example.com/path?q=hello%20world';
        $url = DestinationUrl::fromString($raw);

        expect($url->value())->toBe($raw);
    });

    it('accepts ip literal host (blocking is slice 3)', function () {
        // IP literals and private hosts are allowed structurally in this slice.
        // SSRF-blocking is deferred to slice 3.
        $url = DestinationUrl::fromString('https://192.168.1.1/path');

        expect($url->value())->toBe('https://192.168.1.1/path');
    });

    it('accepts private host in this slice', function () {
        // Private/internal host blocking is enforced in slice 3.
        $url = DestinationUrl::fromString('http://localhost/path');

        expect($url->value())->toBe('http://localhost/path');
    });
});
