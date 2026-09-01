<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

describe('links.slug config', function () {
    it('defines a generated slug of 8 characters', function () {
        expect(config('links.slug.length'))->toBe(8);
    });

    it('defines a lowercase Base36 alphabet', function () {
        expect(config('links.slug.alphabet'))->toBe('abcdefghijklmnopqrstuvwxyz0123456789');
    });

    it('defines inclusive custom alias length bounds of 3 and 48', function () {
        expect(config('links.slug.min_alias_length'))->toBe(3)
            ->and(config('links.slug.max_alias_length'))->toBe(48);
    });

    it('defines separate retry ceilings of 5 for collisions and denylist discards', function () {
        expect(config('links.slug.max_collision_attempts'))->toBe(5)
            ->and(config('links.slug.max_denylist_discards'))->toBe(5);
    });

    it('defines exactly the ten reserved words, all lowercase', function () {
        $reserved = config('links.slug.reserved_words');

        expect($reserved)->toBe([
            'admin',
            'api',
            'login',
            'register',
            'docs',
            'health',
            'status',
            'support',
            'terms',
            'privacy',
        ]);

        $notLowercase = array_values(array_filter(
            $reserved,
            static fn (string $word): bool => $word !== mb_strtolower($word),
        ));

        expect($notLowercase)->toBe([]);
    });

    it('keeps the pre-existing destination config intact', function () {
        expect(config('links.destination'))->toHaveKeys(['keyring', 'active_key_id']);
    });
});
