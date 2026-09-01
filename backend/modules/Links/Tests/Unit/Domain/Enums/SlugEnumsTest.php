<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\Enums\SlugSource;

describe('SlugSource', function () {
    it('exposes exactly automatic and custom with matching backed values', function () {
        expect(SlugSource::cases())->toHaveCount(2)
            ->and(SlugSource::Automatic->value)->toBe('automatic')
            ->and(SlugSource::Custom->value)->toBe('custom');
    });

    it('rejects any value outside automatic and custom', function () {
        expect(fn () => SlugSource::from('generated'))->toThrow(ValueError::class);
    });
});

describe('SlugRejectionReason', function () {
    it('exposes exactly the six stable rejection codes with matching backed values', function () {
        expect(SlugRejectionReason::cases())->toHaveCount(6)
            ->and(SlugRejectionReason::TooShort->value)->toBe('too_short')
            ->and(SlugRejectionReason::TooLong->value)->toBe('too_long')
            ->and(SlugRejectionReason::InvalidCharacters->value)->toBe('invalid_characters')
            ->and(SlugRejectionReason::InvalidBoundary->value)->toBe('invalid_boundary')
            ->and(SlugRejectionReason::ConsecutiveHyphens->value)->toBe('consecutive_hyphens')
            ->and(SlugRejectionReason::ReservedWord->value)->toBe('reserved_word');
    });

    it('rejects any value outside the six stable codes', function () {
        expect(fn () => SlugRejectionReason::from('unknown'))->toThrow(ValueError::class);
    });
});
