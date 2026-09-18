<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Exceptions\LinksDomainException;

/**
 * @param  list<string>  $selfHosts
 */
function fromRaw(string $raw, array $selfHosts = []): DestinationUrl
{
    return DestinationUrl::fromString($raw, new PublicHostClassifier($selfHosts));
}

describe('DestinationUrl::fromString — construction only via the full policy', function () {
    it('accepts a valid http url', function () {
        $url = fromRaw('http://example.com/path');

        expect($url->value())->toBe('http://example.com/path');
    });

    it('accepts a valid https url', function () {
        $url = fromRaw('https://example.com/path');

        expect($url->value())->toBe('https://example.com/path');
    });

    it('rejects ftp scheme', function () {
        fromRaw('ftp://example.com');
    })->throws(LinksDomainException::class, 'The destination URL is not allowed.');

    it('rejects javascript scheme', function () {
        fromRaw('javascript:alert(1)');
    })->throws(LinksDomainException::class);

    it('rejects data scheme', function () {
        fromRaw('data:text/html,<h1>hi</h1>');
    })->throws(LinksDomainException::class);

    it('rejects string without scheme', function () {
        fromRaw('example.com/path');
    })->throws(LinksDomainException::class);

    it('rejects a syntactically invalid url (empty host)', function () {
        fromRaw('https:///path');
    })->throws(LinksDomainException::class);

    it('accepts exactly 2048 characters', function () {
        $base = 'https://example.com/';
        $padding = str_repeat('a', 2048 - strlen($base));
        $raw = $base.$padding;

        expect(strlen($raw))->toBe(2048);

        $url = fromRaw($raw);

        expect($url->value())->toBe($raw);
    });

    it('rejects 2049 characters', function () {
        $base = 'https://example.com/';
        $padding = str_repeat('a', 2049 - strlen($base));
        $raw = $base.$padding;

        expect(strlen($raw))->toBe(2049);

        fromRaw($raw);
    })->throws(LinksDomainException::class);

    it('preserves query string and fragment without alteration', function () {
        $raw = 'https://example.com/path?a=1&b=2#frag';
        $url = fromRaw($raw);

        expect($url->value())->toBe($raw);
    });

    it('preserves percent-encoding without alteration', function () {
        $raw = 'https://example.com/path?q=hello%20world';
        $url = fromRaw($raw);

        expect($url->value())->toBe($raw);
    });

    // Slice 1 (foundation) documented these two cases as "accepted for now", pending this
    // slice's host policy (LDST-10, LDST-12). They now assert rejection.
    it('rejects an IP literal host (LDST-10)', function () {
        fromRaw('https://192.168.1.1/path');
    })->throws(LinksDomainException::class);

    it('rejects a special-use host without a public TLD (LDST-08/LDST-12)', function () {
        fromRaw('http://localhost/path');
    })->throws(LinksDomainException::class);

    it('propagates the specific rejection reason from the policy, unweakened', function () {
        try {
            fromRaw('https://192.168.1.1/path');
        } catch (LinksDomainException $e) {
            expect($e->reason())->toBe(DestinationRejectionReason::IpLiteral)
                ->and($e->errorCode())->toBe(LinksDomainException::INVALID_DESTINATION_URL);

            return;
        }

        throw new RuntimeException('Expected LinksDomainException was not thrown.');
    });
});

describe('DestinationUrl::value — always the normalized value, never the raw input', function () {
    it('returns the normalized value for an input that changes under normalization', function () {
        $url = fromRaw('HTTPS://Example.COM:443/Path');

        expect($url->value())->toBe('https://example.com/Path');
    });

    it('exposes no accessor other than value() and equals() alongside the fromString factory', function () {
        $publicMethods = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(DestinationUrl::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        expect($publicMethods)->toEqualCanonicalizing(['fromString', 'value', 'equals']);
    });

    it('keeps the constructor private — the only public construction path is fromString', function () {
        $constructor = (new ReflectionClass(DestinationUrl::class))->getConstructor();

        expect($constructor)->not->toBeNull()
            ->and($constructor->isPrivate())->toBeTrue();
    });
});

describe('DestinationUrl::equals — compares normalized values', function () {
    it('treats two differently-cased inputs that normalize to the same value as equal', function () {
        $a = fromRaw('https://example.com/path');
        $b = fromRaw('HTTPS://EXAMPLE.COM/path');

        expect($a->equals($b))->toBeTrue();
    });

    it('treats a redundant default port as equal to the port-free form', function () {
        $a = fromRaw('https://example.com/path');
        $b = fromRaw('https://example.com:443/path');

        expect($a->equals($b))->toBeTrue();
    });

    it('treats two structurally different destinations as not equal', function () {
        $a = fromRaw('https://example.com/path-a');
        $b = fromRaw('https://example.com/path-b');

        expect($a->equals($b))->toBeFalse();
    });
});
