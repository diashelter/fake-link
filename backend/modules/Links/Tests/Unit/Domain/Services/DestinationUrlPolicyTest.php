<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Domain\Services\DestinationUrlPolicy;

function destinationPolicyWith(): DestinationUrlPolicy
{
    return new DestinationUrlPolicy;
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
