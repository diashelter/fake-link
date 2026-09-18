<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\Infrastructure\Http\Resources\LinkDetailResource;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function sampleCreatedLink(array $overrides = []): CreatedLinkDto
{
    $defaults = [
        'id' => '01900000-0000-7000-8000-000000000001',
        'slug' => 'abc12def',
        'slugSource' => SlugSource::Automatic,
        'destinationUrl' => 'https://example.com/path',
        'title' => 'Hello',
        'isEnabled' => true,
        'status' => LinkStatus::Active,
        'expiresAt' => new DateTimeImmutable('2030-01-01T00:00:00Z'),
        'blockedAt' => null,
        'createdAt' => new DateTimeImmutable('2026-06-15T10:30:00Z'),
        'updatedAt' => new DateTimeImmutable('2026-06-15T10:30:00Z'),
        'version' => 1,
    ];

    $data = array_merge($defaults, $overrides);

    return new CreatedLinkDto(...$data);
}

describe('LinkDetailResource', function () {
    it('serializes exactly the LinkDetail fields', function () {
        $array = LinkDetailResource::toArray(sampleCreatedLink());

        expect(array_keys($array))->toBe([
            'id',
            'slug',
            'short_url',
            'destination_url',
            'title',
            'slug_source',
            'is_enabled',
            'status',
            'expires_at',
            'created_at',
            'updated_at',
        ])
            ->and($array)->not->toHaveKey('version')
            ->and($array)->not->toHaveKey('blocked_at')
            ->and($array)->not->toHaveKey('user_id');
    });

    it('builds short_url from config base_url not the request host', function () {
        config(['links.short_url.base_url' => 'https://go.configured.test']);

        $array = LinkDetailResource::toArray(sampleCreatedLink(['slug' => 'myslug01']));

        expect($array['short_url'])->toBe('https://go.configured.test/myslug01')
            ->and($array['short_url'])->not->toContain('localhost');
    });

    it('formats dates as ISO 8601 UTC with Z suffix', function () {
        $array = LinkDetailResource::toArray(sampleCreatedLink());

        expect($array['created_at'])->toBe('2026-06-15T10:30:00Z')
            ->and($array['updated_at'])->toBe('2026-06-15T10:30:00Z')
            ->and($array['expires_at'])->toBe('2030-01-01T00:00:00Z');
    });

    it('serializes null title and expires_at', function () {
        $array = LinkDetailResource::toArray(sampleCreatedLink([
            'title' => null,
            'expiresAt' => null,
        ]));

        expect($array['title'])->toBeNull()
            ->and($array['expires_at'])->toBeNull();
    });

    it('exposes slug_source and status as strings', function () {
        $array = LinkDetailResource::toArray(sampleCreatedLink([
            'slugSource' => SlugSource::Custom,
            'status' => LinkStatus::Active,
        ]));

        expect($array['slug_source'])->toBe('custom')
            ->and($array['status'])->toBe('active');
    });
});

describe('LinkResponseFactory::created', function () {
    it('returns 201 with Location, ETag, Cache-Control and X-Request-ID', function () {
        $link = sampleCreatedLink();
        $response = app(LinkResponseFactory::class)->created($link, 'req-1');

        expect($response->getStatusCode())->toBe(201)
            ->and($response->headers->get('Location'))->toBe('/api/v1/links/'.$link->id)
            ->and($response->headers->get('ETag'))->toMatch('/^"[^"]+"$/')
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->toBe('req-1');
    });

    it('wraps LinkDetail under data', function () {
        $link = sampleCreatedLink();
        $payload = json_decode(app(LinkResponseFactory::class)->created($link)->getContent(), true);

        expect($payload)->toHaveKey('data')
            ->and($payload['data']['id'])->toBe($link->id)
            ->and($payload['data'])->not->toHaveKey('version');
    });

    it('uses a strong ETag format without W/ prefix', function () {
        $response = app(LinkResponseFactory::class)->created(sampleCreatedLink());

        expect($response->headers->get('ETag'))->toMatch('/^"[^"]+"$/')
            ->and($response->headers->get('ETag'))->not->toStartWith('W/');
    });

    it('defaults request id when omitted', function () {
        $response = app(LinkResponseFactory::class)->created(sampleCreatedLink());

        expect($response->headers->get('X-Request-ID'))->toBe('stub-request-id');
    });

    it('Location path is relative and includes the link id', function () {
        $link = sampleCreatedLink(['id' => '01900000-0000-7000-8000-000000000099']);
        $response = app(LinkResponseFactory::class)->created($link);

        expect($response->headers->get('Location'))->toBe('/api/v1/links/01900000-0000-7000-8000-000000000099');
    });
});
