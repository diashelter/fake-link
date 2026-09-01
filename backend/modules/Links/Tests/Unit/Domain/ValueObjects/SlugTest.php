<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugPolicyException;

/**
 * Assert that $fn throws SlugPolicyException carrying exactly $expected.
 */
function assertSlugRejected(Closure $fn, SlugRejectionReason $expected): void
{
    try {
        $fn();
    } catch (SlugPolicyException $exception) {
        expect($exception->rejectionReason())->toBe($expected);

        return;
    }

    throw new RuntimeException('Expected SlugPolicyException carrying '.$expected->value.', none thrown.');
}

describe('Slug::fromCustomAlias — normalization', function () {
    it('trims outer ASCII whitespace and lowercases before anything else', function () {
        $slug = Slug::fromCustomAlias('  Architecture  ');

        expect($slug->value())->toBe('architecture')
            ->and($slug->source())->toBe(SlugSource::Custom);
    });

    it('applies normalization before validation so a trimmable, mixed-case input is still valid', function () {
        expect(Slug::fromCustomAlias('  My-Alias  ')->value())->toBe('my-alias');
    });
});

describe('Slug::fromCustomAlias — length bounds', function () {
    it('rejects 2 characters as too_short', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('ab'), SlugRejectionReason::TooShort);
    });

    it('accepts exactly 3 characters', function () {
        expect(Slug::fromCustomAlias('abc')->value())->toBe('abc');
    });

    it('accepts exactly 48 characters', function () {
        $value = str_repeat('a', 48);

        expect(Slug::fromCustomAlias($value)->value())->toBe($value);
    });

    it('rejects 49 characters as too_long', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias(str_repeat('a', 49)), SlugRejectionReason::TooLong);
    });

    it('rejects the empty string as too_short after normalization', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias(''), SlugRejectionReason::TooShort);
    });

    it('rejects a whitespace-only alias as too_short after normalization', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('   '), SlugRejectionReason::TooShort);
    });
});

describe('Slug::fromCustomAlias — character allowlist', function () {
    it('rejects an internal space as invalid_characters', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('my link'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects an underscore as invalid_characters', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('my_link'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects a Cyrillic homoglyph as invalid_characters and never transliterates it', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('аdmin'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects ADMÍN as invalid_characters (not reserved_word) after ASCII lowercasing', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('ADMÍN'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects İstanbul (U+0130) as invalid_characters — no locale-dependent case collapse', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('İstanbul'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects percent-encoding as invalid_characters and never decodes it', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('%61dmin'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects a control character as invalid_characters', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias("a\x01b"), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects an emoji as invalid_characters', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('a😀b'), SlugRejectionReason::InvalidCharacters);
    });
});

describe('Slug::fromCustomAlias — hyphen rules', function () {
    it('rejects a leading hyphen as invalid_boundary', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('-abc'), SlugRejectionReason::InvalidBoundary);
    });

    it('rejects a trailing hyphen as invalid_boundary', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('abc-'), SlugRejectionReason::InvalidBoundary);
    });

    it('rejects "---" as invalid_boundary — the boundary rule fires before consecutive-hyphens', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('---'), SlugRejectionReason::InvalidBoundary);
    });

    it('rejects two consecutive internal hyphens as consecutive_hyphens', function () {
        assertSlugRejected(fn () => Slug::fromCustomAlias('a--b'), SlugRejectionReason::ConsecutiveHyphens);
    });

    it('accepts a single internal hyphen', function () {
        expect(Slug::fromCustomAlias('a-b')->value())->toBe('a-b');
    });
});

describe('Slug::fromCustomAlias — result shape', function () {
    it('marks an accepted alias with source custom', function () {
        expect(Slug::fromCustomAlias('valid-alias')->source())->toBe(SlugSource::Custom);
    });

    it('treats case-equivalent aliases as equal by normalized value', function () {
        expect(Slug::fromCustomAlias('Foo')->equals(Slug::fromCustomAlias('foo')))->toBeTrue();
    });

    it('treats different normalized values as not equal', function () {
        expect(Slug::fromCustomAlias('foo')->equals(Slug::fromCustomAlias('bar')))->toBeFalse();
    });
});

describe('Slug::fromGenerated', function () {
    it('accepts exactly 8 lowercase alphanumerics and marks source automatic', function () {
        $slug = Slug::fromGenerated('a1b2c3d4');

        expect($slug->value())->toBe('a1b2c3d4')
            ->and($slug->source())->toBe(SlugSource::Automatic);
    });

    it('rejects fewer than 8 characters as too_short', function () {
        assertSlugRejected(fn () => Slug::fromGenerated('a1b2c3'), SlugRejectionReason::TooShort);
    });

    it('rejects more than 8 characters as too_long', function () {
        assertSlugRejected(fn () => Slug::fromGenerated('a1b2c3d4e5'), SlugRejectionReason::TooLong);
    });

    it('rejects uppercase in a generated candidate as invalid_characters', function () {
        assertSlugRejected(fn () => Slug::fromGenerated('ABCDEFGH'), SlugRejectionReason::InvalidCharacters);
    });

    it('rejects a hyphen in a generated candidate as invalid_characters', function () {
        assertSlugRejected(fn () => Slug::fromGenerated('abcdef-h'), SlugRejectionReason::InvalidCharacters);
    });
});
