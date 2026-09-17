<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Domain\Services\PublicHostClassifier;

/**
 * @param  list<string>  $selfHosts
 */
function classifierWith(array $selfHosts = []): PublicHostClassifier
{
    return new PublicHostClassifier($selfHosts);
}

describe('PublicHostClassifier::reject — IP literals', function () {
    it('rejects IPv4 literals as IpLiteral', function (string $host) {
        expect(classifierWith()->reject($host))->toBe(DestinationRejectionReason::IpLiteral);
    })->with([
        '127.0.0.1',
        '10.0.0.5',
        '8.8.8.8',
    ]);

    it('rejects bracketed IPv6 literals as IpLiteral', function (string $host) {
        expect(classifierWith()->reject($host))->toBe(DestinationRejectionReason::IpLiteral);
    })->with([
        '[::1]',
        '[fd00::1]',
        '[2606:4700::1111]',
    ]);
});

describe('PublicHostClassifier::reject — hostname syntax', function () {
    it('rejects syntactically invalid hostnames as InvalidHostname', function (string $host) {
        expect(classifierWith()->reject($host))->toBe(DestinationRejectionReason::InvalidHostname);
    })->with([
        'intranet — no dot at all' => 'intranet',
        'wiki — no dot at all' => 'wiki',
        'localhost without a suffix — eliminated by the no-dot rule, not special-use' => 'localhost',
        'empty label' => 'a..b.com',
        'label starting with a hyphen' => '-a.com',
        'label ending with a hyphen' => 'a-.com',
        'label longer than 63 characters' => str_repeat('a', 64).'.com',
        'underscore is not a valid hostname character' => 'exa_mple.com',
        'whole-number IPv4 form has no alphabetic TLD' => '2130706433',
        'hex IPv4 form has no alphabetic TLD' => '0x7f.1',
    ]);

    it('accepts a label of exactly 63 characters', function () {
        $host = str_repeat('a', 63).'.com';

        expect(classifierWith()->reject($host))->toBeNull();
    });

    it('accepts a total hostname length of exactly 253 characters', function () {
        // 63 + 63 + 63 + 58 + 3 dots = 250, plus ".co" (3) = 253.
        $host = str_repeat('a', 63).'.'.str_repeat('a', 63).'.'.str_repeat('a', 63).'.'.str_repeat('a', 58).'.co';
        expect(strlen($host))->toBe(253);

        expect(classifierWith()->reject($host))->toBeNull();
    });

    it('rejects a total hostname length of exactly 254 characters as InvalidHostname', function () {
        // 63 + 63 + 63 + 59 + 3 dots = 251, plus ".co" (3) = 254.
        $host = str_repeat('a', 63).'.'.str_repeat('a', 63).'.'.str_repeat('a', 63).'.'.str_repeat('a', 59).'.co';
        expect(strlen($host))->toBe(254);

        expect(classifierWith()->reject($host))->toBe(DestinationRejectionReason::InvalidHostname);
    });
});

describe('PublicHostClassifier::reject — special-use suffixes', function () {
    it('rejects all nine special-use suffixes as SpecialUseHost', function (string $host) {
        expect(classifierWith()->reject($host))->toBe(DestinationRejectionReason::SpecialUseHost);
    })->with([
        'localhost suffix' => 'qualquer.localhost',
        'local suffix' => 'nas.local',
        'internal suffix' => 'db.internal',
        'home.arpa suffix' => 'x.home.arpa',
        'test suffix' => 'a.test',
        'invalid suffix' => 'a.invalid',
        'example suffix' => 'a.example',
        'onion suffix' => 'x.onion',
        'alt suffix' => 'x.alt',
    ]);

    it('rejects special-use suffixes regardless of case', function (string $host) {
        expect(classifierWith()->reject($host))->toBe(DestinationRejectionReason::SpecialUseHost);
    })->with([
        'QUALQUER.LOCALHOST',
        'DB.INTERNAL',
        'X.Home.Arpa',
        'A.TEST',
    ]);
});

describe('PublicHostClassifier::reject — self hosts', function () {
    it('rejects the exact configured self host as SelfHost', function () {
        expect(classifierWith(['app.example.net'])->reject('app.example.net'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('rejects a subdomain of a configured self host as SelfHost', function () {
        expect(classifierWith(['app.example.net'])->reject('go.app.example.net'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('rejects a deep subdomain of a configured self host as SelfHost', function () {
        expect(classifierWith(['app.example.net'])->reject('a.b.app.example.net'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('rejects a configured self host regardless of case', function () {
        expect(classifierWith(['app.example.net'])->reject('APP.Example.NET'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('does not reject a host that is merely a superstring, not a subdomain, of a self host', function () {
        // "notapp.example.net" is not "app.example.net" nor a subdomain of it.
        expect(classifierWith(['app.example.net'])->reject('notapp.example.net'))->toBeNull();
    });
});

describe('PublicHostClassifier::reject — accepted public hosts', function () {
    it('accepts structurally valid public hostnames matching no rejection rule', function (string $host) {
        expect(classifierWith(['app.example.net'])->reject($host))->toBeNull();
    })->with([
        'example.com',
        'xn--caf-dma.com',
        'mylocalhost.com is a label match, not a substring of the "local" suffix' => 'mylocalhost.com',
        'internal-tools.com is a label match, not a substring of the "internal" suffix' => 'internal-tools.com',
    ]);
});

describe('PublicHostClassifier::reject — self host takes precedence over special-use suffix', function () {
    // Regression for LDST-13 / spec.md AC7: a real self_host such as go.localhost
    // itself ends in the special-use suffix ".localhost". reject() must classify
    // it as SelfHost, not SpecialUseHost — checking self-host before special-use.
    it('classifies an exact self host ending in a special-use suffix as SelfHost', function () {
        expect(classifierWith(['go.localhost'])->reject('go.localhost'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('classifies a case-mixed self host as SelfHost (trailing-dot trimming is the caller\'s job — DestinationUrlPolicy, tested there)', function () {
        expect(classifierWith(['go.localhost'])->reject('GO.Localhost'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('classifies a subdomain of a self host ending in a special-use suffix as SelfHost', function () {
        expect(classifierWith(['go.localhost'])->reject('abc.go.localhost'))
            ->toBe(DestinationRejectionReason::SelfHost);
    });

    it('still classifies a special-use suffix host that is not a configured self host as SpecialUseHost', function () {
        expect(classifierWith(['go.localhost'])->reject('other.localhost'))
            ->toBe(DestinationRejectionReason::SpecialUseHost);
    });
});

describe('PublicHostClassifier — no I/O', function () {
    it('classifies a syntactically valid, unresolvable host without any DNS lookup or network I/O', function () {
        // RFC 2606 reserves ".invalid" as guaranteed never to resolve. If the classifier performed
        // any DNS resolution, repeated calls would incur resolver latency/timeouts instead of
        // returning immediately — a pure, deterministic function must not depend on the network.
        $host = 'definitely-unresolvable-verification-host.invalid';
        $classifier = classifierWith();
        $result = $classifier->reject($host);

        $start = microtime(true);
        for ($i = 0; $i < 50; $i++) {
            $result = $classifier->reject($host);
        }
        $elapsed = microtime(true) - $start;

        expect($result)->toBe(DestinationRejectionReason::SpecialUseHost)
            ->and($elapsed)->toBeLessThan(0.1);
    });
});
