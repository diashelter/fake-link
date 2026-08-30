<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\LinkStatus;

describe('LinkStatus', function () {
    it('exposes the four canonical values', function () {
        expect(LinkStatus::cases())->toHaveCount(4)
            ->and(LinkStatus::Active->value)->toBe('active')
            ->and(LinkStatus::Inactive->value)->toBe('inactive')
            ->and(LinkStatus::Expired->value)->toBe('expired')
            ->and(LinkStatus::Blocked->value)->toBe('blocked');
    });

    it('rejects invalid status strings', function () {
        LinkStatus::fromString('unknown');
    })->throws(ValueError::class);
});
