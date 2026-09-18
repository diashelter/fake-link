<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;
use Modules\Links\Infrastructure\Http\Responses\LinkCreationSnapshotFactory;
use Modules\Links\Infrastructure\Http\Responses\LinkResponseFactory;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function responseFactorySampleLink(array $overrides = []): CreatedLinkDto
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

    return new CreatedLinkDto(...array_merge($defaults, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function sampleIdempotencySnapshot(array $overrides = []): IdempotencyResponseSnapshot
{
    $defaults = [
        'status' => 201,
        'headers' => [
            'Location' => '/api/v1/links/01900000-0000-7000-8000-000000000001',
            'ETag' => '"abcdef0123456789"',
            'Cache-Control' => 'private, no-store',
        ],
        'body' => '{"data":{"id":"01900000-0000-7000-8000-000000000001"}}',
    ];

    $data = array_merge($defaults, $overrides);

    return new IdempotencyResponseSnapshot(
        status: $data['status'],
        headers: $data['headers'],
        body: $data['body'],
    );
}

describe('LinkResponseFactory::fromSnapshot', function () {
    it('replays status, Location, ETag, Cache-Control and body bytes literally', function () {
        $snapshot = sampleIdempotencySnapshot();
        $response = app(LinkResponseFactory::class)->fromSnapshot($snapshot, 'req-replay-1');

        expect($response->getStatusCode())->toBe(201)
            ->and($response->headers->get('Location'))->toBe($snapshot->headers['Location'])
            ->and($response->headers->get('ETag'))->toBe($snapshot->headers['ETag'])
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->getContent())->toBe($snapshot->body)
            ->and($response->headers->get('X-Request-ID'))->toBe('req-replay-1');
    });

    it('applies a fresh X-Request-ID on each replay of the same snapshot', function () {
        $snapshot = sampleIdempotencySnapshot();
        $factory = app(LinkResponseFactory::class);

        $first = $factory->fromSnapshot($snapshot, 'req-a');
        $second = $factory->fromSnapshot($snapshot, 'req-b');

        expect($first->getContent())->toBe($second->getContent())
            ->and($first->headers->get('Location'))->toBe($second->headers->get('Location'))
            ->and($first->headers->get('ETag'))->toBe($second->headers->get('ETag'))
            ->and($first->headers->get('X-Request-ID'))->toBe('req-a')
            ->and($second->headers->get('X-Request-ID'))->toBe('req-b');
    });

    it('defaults X-Request-ID when omitted', function () {
        $response = app(LinkResponseFactory::class)->fromSnapshot(sampleIdempotencySnapshot());

        expect($response->headers->get('X-Request-ID'))->toBe('stub-request-id');
    });

    it('discriminates mutations on status, body bytes, and each semantic header', function (string $field, callable $mutate) {
        $baseline = sampleIdempotencySnapshot();
        $mutated = $mutate(sampleIdempotencySnapshot());
        $factory = app(LinkResponseFactory::class);

        $baseResponse = $factory->fromSnapshot($baseline, 'req');
        $mutatedResponse = $factory->fromSnapshot($mutated, 'req');

        $identical = $baseResponse->getContent() === $mutatedResponse->getContent()
            && $baseResponse->getStatusCode() === $mutatedResponse->getStatusCode()
            && $baseResponse->headers->get('Location') === $mutatedResponse->headers->get('Location')
            && $baseResponse->headers->get('ETag') === $mutatedResponse->headers->get('ETag')
            && $baseResponse->headers->get('Cache-Control') === $mutatedResponse->headers->get('Cache-Control');

        expect($identical)->toBeFalse("mutation of {$field} should change the HTTP response");
    })->with([
        'status' => [
            'status',
            fn (IdempotencyResponseSnapshot $s): IdempotencyResponseSnapshot => new IdempotencyResponseSnapshot(
                status: 200,
                headers: $s->headers,
                body: $s->body,
            ),
        ],
        'body' => [
            'body',
            fn (IdempotencyResponseSnapshot $s): IdempotencyResponseSnapshot => new IdempotencyResponseSnapshot(
                status: $s->status,
                headers: $s->headers,
                body: $s->body.' ',
            ),
        ],
        'Location' => [
            'Location',
            fn (IdempotencyResponseSnapshot $s): IdempotencyResponseSnapshot => new IdempotencyResponseSnapshot(
                status: $s->status,
                headers: [...$s->headers, 'Location' => '/api/v1/links/other'],
                body: $s->body,
            ),
        ],
        'ETag' => [
            'ETag',
            fn (IdempotencyResponseSnapshot $s): IdempotencyResponseSnapshot => new IdempotencyResponseSnapshot(
                status: $s->status,
                headers: [...$s->headers, 'ETag' => '"mutated"'],
                body: $s->body,
            ),
        ],
        'Cache-Control' => [
            'Cache-Control',
            fn (IdempotencyResponseSnapshot $s): IdempotencyResponseSnapshot => new IdempotencyResponseSnapshot(
                status: $s->status,
                headers: [...$s->headers, 'Cache-Control' => 'no-cache'],
                body: $s->body,
            ),
        ],
    ]);

    it('created and fromSnapshot share the same body bytes for the same link', function () {
        $link = responseFactorySampleLink();
        $factory = app(LinkResponseFactory::class);
        $snapshot = app(LinkCreationSnapshotFactory::class)->fromCreated($link);

        $created = $factory->created($link, 'req-1');
        $replayed = $factory->fromSnapshot($snapshot, 'req-2');

        expect($created->getContent())->toBe($replayed->getContent())
            ->and($created->getContent())->toBe($snapshot->body)
            ->and($created->headers->get('Location'))->toBe($replayed->headers->get('Location'))
            ->and($created->headers->get('ETag'))->toBe($replayed->headers->get('ETag'))
            ->and($created->headers->get('Cache-Control'))->toContain('private')
            ->and($created->headers->get('Cache-Control'))->toContain('no-store')
            ->and($replayed->headers->get('Cache-Control'))->toContain('private')
            ->and($replayed->headers->get('Cache-Control'))->toContain('no-store')
            ->and($created->headers->get('X-Request-ID'))->toBe('req-1')
            ->and($replayed->headers->get('X-Request-ID'))->toBe('req-2');
    });
});
