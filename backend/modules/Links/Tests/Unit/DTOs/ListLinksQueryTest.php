<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\LinkQueryScope;

describe('ListLinksQuery', function () {
    it('defaults per_page to 20 when omitted', function () {
        $query = ListLinksQuery::from();

        expect($query->perPage)->toBe(20)
            ->and($query->search)->toBeNull()
            ->and($query->status)->toBeNull();
    });

    it('accepts per_page integers from 1 to 100 inclusive', function (int $perPage) {
        $query = ListLinksQuery::from(perPage: $perPage);

        expect($query->perPage)->toBe($perPage);
    })->with([1, 20, 100]);

    it('rejects per_page outside 1–100', function (int $perPage) {
        expect(fn () => ListLinksQuery::from(perPage: $perPage))
            ->toThrow(InvalidArgumentException::class);
    })->with([0, 101, -1]);

    it('does not bind per_page into the cursor scope', function () {
        $twenty = ListLinksQuery::from(perPage: 20, search: 'campanha', status: 'active');
        $fifty = ListLinksQuery::from(perPage: 50, search: 'campanha', status: 'active');

        expect($twenty->scope())->toEqual($fifty->scope())
            ->and($twenty->scope())->toEqual(new LinkQueryScope(
                search: 'campanha',
                status: LinkStatus::Active,
            ));
    });

    it('trims search before applying the 2–160 character limit', function () {
        $query = ListLinksQuery::from(search: '  ação  ');

        expect($query->search)->toBe('ação');
    });

    it('accepts search at the inclusive 2 and 160 character bounds', function (string $search) {
        $query = ListLinksQuery::from(search: $search);

        expect($query->search)->toBe($search)
            ->and(mb_strlen((string) $query->search))->toBe(mb_strlen($search));
    })->with([
        'ab',
        str_repeat('a', 160),
    ]);

    it('rejects search that is empty after trim or outside 2–160 characters', function (string $search) {
        expect(fn () => ListLinksQuery::from(search: $search))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        '',
        ' ',
        'a',
        str_repeat('a', 161),
    ]);

    it('treats absent search as no textual filter', function () {
        $query = ListLinksQuery::from(search: null);

        expect($query->search)->toBeNull()
            ->and($query->scope()->search)->toBeNull();
    });

    it('treats absent or all status as every effective status', function (?string $status) {
        $query = ListLinksQuery::from(status: $status);

        expect($query->status)->toBeNull()
            ->and($query->scope()->status)->toBeNull();
    })->with([null, 'all']);

    it('binds a supported effective status onto the scope', function (string $status, LinkStatus $expected) {
        $query = ListLinksQuery::from(status: $status);

        expect($query->status)->toBe($expected)
            ->and($query->scope()->status)->toBe($expected);
    })->with([
        ['active', LinkStatus::Active],
        ['inactive', LinkStatus::Inactive],
        ['expired', LinkStatus::Expired],
        ['blocked', LinkStatus::Blocked],
    ]);

    it('rejects an unsupported status value', function () {
        expect(fn () => ListLinksQuery::from(status: 'archived'))
            ->toThrow(InvalidArgumentException::class);
    });
});
