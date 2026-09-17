<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Domain\Services\DestinationUrlPolicy;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Exceptions\LinksDomainException;

/**
 * @param  list<string>  $selfHosts
 */
function destinationPolicyWith(array $selfHosts = []): DestinationUrlPolicy
{
    return new DestinationUrlPolicy(new PublicHostClassifier($selfHosts));
}

describe('DestinationUrlPolicy — pre-parse: raw length (LDST-02)', function () {
    it('accepts a raw value of exactly 2048 characters', function () {
        $raw = 'https://example.com/'.str_repeat('a', 2048 - strlen('https://example.com/'));

        expect(strlen($raw))->toBe(2048)
            ->and(destinationPolicyWith()->reject($raw))->toBeNull();
    });

    it('rejects a raw value of 2049 characters as TooLong', function () {
        $raw = 'https://example.com/'.str_repeat('a', 2049 - strlen('https://example.com/'));

        expect(strlen($raw))->toBe(2049)
            ->and(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::TooLong);
    });
});

describe('DestinationUrlPolicy — pre-parse: border whitespace trim', function () {
    it('trims leading and trailing spaces before evaluating the rest of the chain', function () {
        $policy = destinationPolicyWith();

        expect($policy->reject(' https://example.com/x '))->toBeNull()
            ->and($policy->normalize(' https://example.com/x '))->toBe('https://example.com/x');
    });

    it('trims a tab at the border without triggering ControlCharacter', function () {
        expect(destinationPolicyWith()->reject("\thttps://example.com/x\t"))->toBeNull();
    });

    it('accepts a raw value that is 2050 characters with border spaces that trims under the 2048 limit', function () {
        // Documented edge case: whitespace at the border is not counted as content — trim runs
        // before the length check, so a value that is too long only because of border padding
        // is accepted once trimmed.
        $inner = 'https://example.com/'.str_repeat('a', 2047 - strlen('https://example.com/'));
        expect(strlen($inner))->toBe(2047);

        $raw = '   '.$inner; // 3 leading spaces -> 2050 raw characters, 2047 after trim.
        expect(strlen($raw))->toBe(2050)
            ->and(destinationPolicyWith()->reject($raw))->toBeNull();
    });
});

describe('DestinationUrlPolicy — pre-parse: control characters (LDST-04)', function () {
    it('rejects internal control characters as ControlCharacter', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::ControlCharacter);
    })->with([
        'internal tab' => "https://example.com/\tx",
        'internal carriage return' => "https://example.com/\rx",
        'internal newline' => "https://example.com/\nx",
        'internal DEL (U+007F)' => "https://example.com/\x7Fx",
        'internal NUL (U+0000)' => "https://example.com/\x00x",
        'internal unit separator (U+001F)' => "https://example.com/\x1Fx",
    ]);
});

describe('DestinationUrlPolicy — pre-parse: non-ASCII bytes (LDST-09)', function () {
    it('rejects any non-ASCII byte as NonAsciiInput', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::NonAsciiInput);
    })->with([
        'non-ascii host' => 'https://café.com/x',
        'non-ascii path' => 'https://example.com/pá',
        'non-ascii query' => 'https://example.com/q?a=á',
    ]);
});

describe('DestinationUrlPolicy — pre-parse: percent-encoding (LDST-06)', function () {
    it('rejects malformed percent-encoding as InvalidPercentEncoding', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::InvalidPercentEncoding);
    })->with([
        'non-hex sequence' => 'https://example.com/%zz',
        'truncated at end (single hex digit)' => 'https://example.com/%A',
        'isolated percent sign' => 'https://example.com/100%',
    ]);

    it('accepts well-formed percent-encoding unchanged', function () {
        $raw = 'https://example.com/a%2Fb?q=%C3%A1';

        expect(destinationPolicyWith()->reject($raw))->toBeNull()
            ->and(destinationPolicyWith()->normalize($raw))->toBe($raw);
    });

    it('proves percent-encoding is checked before the value could ever reach a URL parser: an isolated "%" is rejected as InvalidPercentEncoding even though the rest of the string also violates a not-yet-implemented rule', function () {
        // At this stage the chain has no scheme/host parsing yet, so this proves ordering
        // structurally: the pre-parse percent-encoding check runs unconditionally, before
        // any later rule could apply, and never lets a bare "%" survive to be rewritten
        // (league/uri would otherwise silently turn "%" into "%25").
        expect(destinationPolicyWith()->reject('ftp://example.com/%'))
            ->toBe(DestinationRejectionReason::InvalidPercentEncoding);
    });
});

describe('DestinationUrlPolicy — pre-parse order: non-ASCII/control checked before percent-encoding', function () {
    it('reports NonAsciiInput (step 3) rather than InvalidPercentEncoding (step 4) when both are present', function () {
        expect(destinationPolicyWith()->reject('https://café.com/%zz'))
            ->toBe(DestinationRejectionReason::NonAsciiInput);
    });
});

describe('DestinationUrlPolicy — scheme (LDST-01)', function () {
    it('rejects disallowed or absent schemes as SchemeNotAllowed', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::SchemeNotAllowed);
    })->with([
        'ftp' => 'ftp://example.com/x',
        'javascript' => 'javascript:alert(1)',
        'data' => 'data:text/html,<h1>hi</h1>',
        'file' => 'file:///etc/passwd',
        'scheme absent' => 'example.com/path',
    ]);

    it('accepts http and https', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBeNull();
    })->with([
        'http://example.com/x',
        'https://example.com/x',
    ]);
});

describe('DestinationUrlPolicy — userinfo (LDST-05)', function () {
    it('rejects every userinfo form as UserinfoPresent', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::UserinfoPresent);
    })->with([
        'user and password' => 'https://u:p@example.com/x',
        'user only' => 'https://u@example.com/x',
        'empty userinfo' => 'https://@example.com/x',
        'empty user, empty password' => 'https://:@example.com/x',
    ]);
});

describe('DestinationUrlPolicy — malformed URL (LDST-07)', function () {
    it('rejects syntactically invalid URLs as MalformedUrl, without a PHP error or warning', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::MalformedUrl);
    })->with([
        'scheme with no authority nor host' => 'https://',
        'triple slash, empty host' => 'http:///path',
        'space inside the host' => 'https://ho st.com/',
    ]);

    it('never chains or propagates the parser SyntaxError: the raw URL does not leak into the public exception', function () {
        $raw = 'https://ho st.com/secret-marker-should-not-leak';

        try {
            destinationPolicyWith()->normalize($raw);
        } catch (LinksDomainException $e) {
            expect($e->reason())->toBe(DestinationRejectionReason::MalformedUrl)
                ->and($e->getMessage())->not->toContain('secret-marker-should-not-leak')
                ->and($e->getPrevious())->toBeNull()
                ->and($e->getTraceAsString())->not->toContain('secret-marker-should-not-leak');

            return;
        }

        throw new RuntimeException('Expected LinksDomainException was not thrown.');
    });

    // SPEC_DEVIATION: verified with league/uri 7.8.1 — a non-numeric or negative port (e.g.
    // ":-1", ":abc") is itself a URI syntax violation (RFC 3986 port = *DIGIT), so Uri::new()
    // throws SyntaxError before step 9's port-range check is reachable. See the SPEC_DEVIATION
    // comment in DestinationUrlPolicy::evaluate() for the full reasoning. Only a syntactically
    // valid (all-digit) out-of-range port reaches InvalidPort — covered in the port describe
    // block below. The public contract (422 INVALID_DESTINATION_URL) is unaffected.
    it('classifies a syntactically invalid port as MalformedUrl, not InvalidPort', function (string $raw) {
        expect(destinationPolicyWith()->reject($raw))->toBe(DestinationRejectionReason::MalformedUrl);
    })->with([
        'negative port' => 'https://example.com:-1/x',
        'non-numeric port' => 'https://example.com:abc/x',
    ]);
});

describe('DestinationUrlPolicy — host classification delegated to PublicHostClassifier (LDST-08, LDST-10 … LDST-13)', function () {
    it('rejects an IPv4 literal host as IpLiteral', function () {
        expect(destinationPolicyWith()->reject('https://127.0.0.1/x'))->toBe(DestinationRejectionReason::IpLiteral);
    });

    it('rejects a bracketed IPv6 literal host as IpLiteral', function () {
        expect(destinationPolicyWith()->reject('https://[::1]/x'))->toBe(DestinationRejectionReason::IpLiteral);
    });

    it('rejects a special-use suffix host as SpecialUseHost', function () {
        expect(destinationPolicyWith()->reject('https://a.localhost/x'))->toBe(DestinationRejectionReason::SpecialUseHost);
    });

    it('rejects a configured self host as SelfHost', function () {
        expect(destinationPolicyWith(['go.localhost'])->reject('https://go.localhost/x'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('rejects a self host in upper case with a trailing FQDN dot as SelfHost — proving the host is trimmed and normalized before classification (spec.md AC7)', function () {
        expect(destinationPolicyWith(['go.localhost'])->reject('HTTPS://GO.LOCALHOST./abc'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('accepts a structurally valid public host', function () {
        expect(destinationPolicyWith()->reject('https://example.com/x'))->toBeNull();
    });
});

describe('DestinationUrlPolicy — port range (LDST-14)', function () {
    it('rejects port 0 as InvalidPort', function () {
        expect(destinationPolicyWith()->reject('https://example.com:0/x'))->toBe(DestinationRejectionReason::InvalidPort);
    });

    it('rejects a port greater than 65535 as InvalidPort', function () {
        expect(destinationPolicyWith()->reject('https://example.com:65536/x'))->toBe(DestinationRejectionReason::InvalidPort);
    });

    it('accepts a valid custom port', function () {
        expect(destinationPolicyWith()->reject('https://example.com:8443/x'))->toBeNull();
    });
});

describe('DestinationUrlPolicy — fixed evaluation order (spec.md: "Ordem de avaliação")', function () {
    it('reports the earlier rule in the chain when an input violates two rules at once', function () {
        // Violates both scheme (ftp is not allowed) and userinfo (u:p@ present) — scheme is
        // evaluated first (step 6), so SchemeNotAllowed must win over UserinfoPresent.
        expect(destinationPolicyWith()->reject('ftp://u:p@example.com/x'))
            ->toBe(DestinationRejectionReason::SchemeNotAllowed);
    });

    it('reports userinfo before host classification when both are violated', function () {
        // Violates both userinfo (u@ present) and host (127.0.0.1 is an IP literal) — userinfo
        // is evaluated first (step 7), so UserinfoPresent must win over IpLiteral.
        expect(destinationPolicyWith()->reject('https://u@127.0.0.1/x'))
            ->toBe(DestinationRejectionReason::UserinfoPresent);
    });

    it('reports host classification before port range when both are violated', function () {
        // Violates both host (127.0.0.1 is an IP literal) and port (65536 is out of range) —
        // host is evaluated first (step 8), so IpLiteral must win over InvalidPort.
        expect(destinationPolicyWith()->reject('https://127.0.0.1:65536/x'))
            ->toBe(DestinationRejectionReason::IpLiteral);
    });
});
