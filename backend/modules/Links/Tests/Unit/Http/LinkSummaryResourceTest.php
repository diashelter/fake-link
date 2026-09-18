<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\DTOs\Output\LinkSummaryRecord;
use Modules\Links\Infrastructure\Http\Resources\LinkSummaryResource;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function sampleSummary(array $overrides = []): LinkSummaryRecord
{
    $defaults = [
        'id' => '01900000-0000-7000-8000-000000000001',
        'slug' => 'abc12def',
        'slugSource' => SlugSource::Automatic,
        'title' => 'Hello',
        'isEnabled' => true,
        'status' => LinkStatus::Active,
        'expiresAt' => new DateTimeImmutable('2030-01-01T00:00:00Z'),
        'createdAt' => new DateTimeImmutable('2026-06-15T10:30:00Z'),
        'updatedAt' => new DateTimeImmutable('2026-06-15T10:30:00Z'),
    ];

    return new LinkSummaryRecord(...array_merge($defaults, $overrides));
}

describe('LinkSummaryResource', function () {
    it('serializes exactly the LinkSummary fields and omits destination, ETag, version, blocked_at and user_id', function () {
        $array = LinkSummaryResource::toArray(sampleSummary());

        expect(array_keys($array))->toBe([
            'id',
            'slug',
            'short_url',
            'title',
            'slug_source',
            'is_enabled',
            'status',
            'expires_at',
            'created_at',
            'updated_at',
        ])
            ->and($array['id'])->toBe('01900000-0000-7000-8000-000000000001')
            ->and($array['slug'])->toBe('abc12def')
            ->and($array['title'])->toBe('Hello')
            ->and($array['slug_source'])->toBe('automatic')
            ->and($array['is_enabled'])->toBeTrue()
            ->and($array['status'])->toBe('active')
            ->and($array)->not->toHaveKey('destination_url')
            ->and($array)->not->toHaveKey('ETag')
            ->and($array)->not->toHaveKey('etag')
            ->and($array)->not->toHaveKey('version')
            ->and($array)->not->toHaveKey('blocked_at')
            ->and($array)->not->toHaveKey('user_id');
    });

    it('builds short_url from config base_url and formats dates as ISO 8601 UTC with Z', function () {
        config(['links.short_url.base_url' => 'https://go.configured.test']);

        $array = LinkSummaryResource::toArray(sampleSummary(['slug' => 'myslug01']));

        expect($array['short_url'])->toBe('https://go.configured.test/myslug01')
            ->and($array['created_at'])->toBe('2026-06-15T10:30:00Z')
            ->and($array['updated_at'])->toBe('2026-06-15T10:30:00Z')
            ->and($array['expires_at'])->toBe('2030-01-01T00:00:00Z');
    });

    it('serializes null title and expires_at', function () {
        $array = LinkSummaryResource::toArray(sampleSummary([
            'title' => null,
            'expiresAt' => null,
        ]));

        expect($array['title'])->toBeNull()
            ->and($array['expires_at'])->toBeNull();
    });
});
