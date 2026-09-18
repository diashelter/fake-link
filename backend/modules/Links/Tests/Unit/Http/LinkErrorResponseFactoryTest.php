<?php

declare(strict_types=1);

use Modules\Links\Exceptions\IdempotencyKeyReused;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Tests\TestCase;

uses(TestCase::class);

describe('LinkErrorResponseFactory', function () {
    it('builds 409 ALIAS_UNAVAILABLE with stable envelope', function () {
        $response = (new LinkErrorResponseFactory)->aliasUnavailable('req-a');
        $body = $response->getData(true);

        expect($response->getStatusCode())->toBe(409)
            ->and($body['code'])->toBe('ALIAS_UNAVAILABLE')
            ->and($body['message'])->toBeString()
            ->and($body['request_id'])->toBe('req-a')
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->toBe('req-a');
    });

    it('builds 503 SLUG_GENERATION_FAILED with Retry-After >= 1', function () {
        $response = (new LinkErrorResponseFactory)->slugGenerationFailed(0, 'req-b');
        $body = $response->getData(true);

        expect($response->getStatusCode())->toBe(503)
            ->and($body['code'])->toBe(SlugGenerationExhausted::ERROR_CODE)
            ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
            ->and($response->headers->get('X-Request-ID'))->toBe('req-b');
    });

    it('builds 429 RATE_LIMIT_EXCEEDED with Retry-After >= 1', function () {
        $response = (new LinkErrorResponseFactory)->rateLimitExceeded(0, 'req-c');
        $body = $response->getData(true);

        expect($response->getStatusCode())->toBe(429)
            ->and($body['code'])->toBe('RATE_LIMIT_EXCEEDED')
            ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->toBe('req-c');
    });

    it('builds 503 SERVICE_UNAVAILABLE', function () {
        $response = (new LinkErrorResponseFactory)->serviceUnavailable('req-d');
        $body = $response->getData(true);

        expect($response->getStatusCode())->toBe(503)
            ->and($body['code'])->toBe('SERVICE_UNAVAILABLE')
            ->and($body['request_id'])->toBe('req-d')
            ->and($response->headers->get('X-Request-ID'))->toBe('req-d');
    });

    it('builds 409 IDEMPOTENCY_KEY_REUSED without leaking key or destination details', function () {
        $response = (new LinkErrorResponseFactory)->idempotencyKeyReused(null, 'req-idem');
        $body = $response->getData(true);
        $json = json_encode($body);

        expect($response->getStatusCode())->toBe(409)
            ->and($body['code'])->toBe(IdempotencyKeyReused::ERROR_CODE)
            ->and($body['request_id'])->toBe('req-idem')
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($json)->not->toContain('https://')
            ->and($json)->not->toContain('Idempotency-Key')
            ->and($json)->not->toContain('snapshot');
    });

    it('maps SlugUnavailable to aliasUnavailable', function () {
        $response = (new LinkErrorResponseFactory)->fromSlugUnavailable(SlugUnavailable::reserved());

        expect($response->getStatusCode())->toBe(409)
            ->and($response->getData(true)['code'])->toBe('ALIAS_UNAVAILABLE');
    });

    it('maps SlugGenerationExhausted to slugGenerationFailed', function () {
        $response = (new LinkErrorResponseFactory)->fromSlugGenerationExhausted(
            SlugGenerationExhausted::exhausted(),
            5,
        );

        expect($response->getStatusCode())->toBe(503)
            ->and($response->getData(true)['code'])->toBe('SLUG_GENERATION_FAILED')
            ->and($response->headers->get('Retry-After'))->toBe('5');
    });

    it('never leaks alias, destination, title or occupant data', function () {
        $factory = new LinkErrorResponseFactory;
        $responses = [
            $factory->aliasUnavailable(),
            $factory->slugGenerationFailed(1),
            $factory->rateLimitExceeded(1),
            $factory->notFound(),
            $factory->serviceUnavailable(),
            $factory->idempotencyKeyReused(),
        ];

        foreach ($responses as $response) {
            $json = json_encode($response->getData(true));
            expect($json)->not->toContain('my-alias')
                ->and($json)->not->toContain('https://')
                ->and($json)->not->toContain('destination')
                ->and($json)->not->toContain('title')
                ->and($json)->not->toContain('user_id')
                ->and($json)->not->toContain('occupant');
        }
    });

    it('defaults request_id when omitted', function () {
        $body = (new LinkErrorResponseFactory)->aliasUnavailable()->getData(true);

        expect($body['request_id'])->toBe('stub-request-id');
    });
});
