<?php

declare(strict_types=1);

use Modules\Links\Contracts\Services\ReservedSlugs;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Tests\TestCase;

uses(TestCase::class);

describe('ConfigReservedSlugs', function () {
    it('implements the ReservedSlugs contract', function () {
        expect(new ConfigReservedSlugs([]))->toBeInstanceOf(ReservedSlugs::class);
    });

    it('returns true for a word present in the list', function () {
        $reserved = new ConfigReservedSlugs(['admin', 'api']);

        expect($reserved->contains('admin'))->toBeTrue();
    });

    it('matches by exact equality, not by prefix', function () {
        $reserved = new ConfigReservedSlugs(['admin']);

        expect($reserved->contains('admin-panel'))->toBeFalse();
    });

    it('matches by exact equality, not by containment', function () {
        $reserved = new ConfigReservedSlugs(['admin']);

        expect($reserved->contains('myadmin'))->toBeFalse();
    });

    it('does not treat a superstring of a reserved word as reserved', function () {
        $reserved = new ConfigReservedSlugs(['api']);

        expect($reserved->contains('apis'))->toBeFalse();
    });

    it('accepts an empty list as valid configuration without throwing', function () {
        $reserved = new ConfigReservedSlugs([]);

        expect($reserved->contains('admin'))->toBeFalse();
    });

    it('sources the denylist from the injected list, not from a constant', function () {
        $reserved = new ConfigReservedSlugs(['zzz-not-a-real-word']);

        expect($reserved->contains('zzz-not-a-real-word'))->toBeTrue()
            ->and($reserved->contains('admin'))->toBeFalse();
    });

    it('reads config(links.slug.reserved_words) when no list is injected', function () {
        $reserved = new ConfigReservedSlugs;

        expect($reserved->contains('login'))->toBeTrue()
            ->and($reserved->contains('not-configured'))->toBeFalse();
    });
});
