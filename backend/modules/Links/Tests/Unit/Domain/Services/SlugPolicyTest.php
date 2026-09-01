<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;

/**
 * @param  list<string>  $denylist
 */
function policyWith(array $denylist = []): SlugPolicy
{
    return new SlugPolicy(new ConfigReservedSlugs($denylist));
}

/**
 * Assert $fn throws SlugPolicyException carrying exactly $expected.
 */
function assertPolicyRejected(Closure $fn, SlugRejectionReason $expected): void
{
    try {
        $fn();
    } catch (SlugPolicyException $exception) {
        expect($exception->rejectionReason())->toBe($expected);

        return;
    }

    throw new RuntimeException('Expected SlugPolicyException carrying '.$expected->value.', none thrown.');
}

describe('SlugPolicy::fromCustomAlias', function () {
    it('returns a custom-source Slug for a structurally valid, non-reserved alias', function () {
        $slug = policyWith(['admin'])->fromCustomAlias('My-Alias');

        expect($slug->value())->toBe('my-alias')
            ->and($slug->source())->toBe(SlugSource::Custom);
    });

    it('runs structural validation before the denylist — ADMÍN fails as invalid_characters, not reserved_word', function () {
        assertPolicyRejected(
            fn () => policyWith(['admin'])->fromCustomAlias('ADMÍN'),
            SlugRejectionReason::InvalidCharacters,
        );
    });

    it('rejects an exact reserved word as reserved_word', function () {
        assertPolicyRejected(
            fn () => policyWith(['admin'])->fromCustomAlias('admin'),
            SlugRejectionReason::ReservedWord,
        );
    });

    it('rejects an uppercase reserved word as reserved_word after normalization', function () {
        assertPolicyRejected(
            fn () => policyWith(['admin'])->fromCustomAlias('ADMIN'),
            SlugRejectionReason::ReservedWord,
        );
    });

    it('rejects a mixed-case reserved word as reserved_word after normalization', function () {
        assertPolicyRejected(
            fn () => policyWith(['admin'])->fromCustomAlias('Admin'),
            SlugRejectionReason::ReservedWord,
        );
    });

    it('checks the denylist against the normalized value, including trimmed input', function () {
        assertPolicyRejected(
            fn () => policyWith(['admin'])->fromCustomAlias('  ADMIN  '),
            SlugRejectionReason::ReservedWord,
        );
    });

    it('accepts a non-exact match of a reserved word', function () {
        expect(policyWith(['admin'])->fromCustomAlias('admin-panel')->value())->toBe('admin-panel');
    });

    it('still surfaces a structural rejection from the value object', function () {
        assertPolicyRejected(
            fn () => policyWith(['admin'])->fromCustomAlias('ab'),
            SlugRejectionReason::TooShort,
        );
    });
});

describe('SlugPolicy::fromGenerated', function () {
    it('returns an automatic-source Slug for a structurally valid, non-reserved candidate', function () {
        $slug = policyWith(['elephant'])->fromGenerated('a1b2c3d4');

        expect($slug->value())->toBe('a1b2c3d4')
            ->and($slug->source())->toBe(SlugSource::Automatic);
    });

    it('applies the same denylist to a generated candidate (SLG-13)', function () {
        assertPolicyRejected(
            fn () => policyWith(['elephant'])->fromGenerated('elephant'),
            SlugRejectionReason::ReservedWord,
        );
    });

    it('still surfaces a structural rejection for a generated candidate', function () {
        assertPolicyRejected(
            fn () => policyWith([])->fromGenerated('a1b2c3'),
            SlugRejectionReason::TooShort,
        );
    });
});

describe('SlugPolicy::isReserved', function () {
    it('delegates to the injected ReservedSlugs', function () {
        $policy = policyWith(['foo']);

        expect($policy->isReserved('foo'))->toBeTrue()
            ->and($policy->isReserved('bar'))->toBeFalse();
    });
});
