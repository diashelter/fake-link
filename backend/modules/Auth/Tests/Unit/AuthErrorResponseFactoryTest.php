<?php

declare(strict_types=1);

use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Exceptions\ResourceNotFoundException;
use Modules\Auth\Infrastructure\Http\Responses\AuthErrorResponseFactory;
use Tests\TestCase;

uses(TestCase::class);

describe('AuthErrorResponseFactory', function () {
    it('builds unauthenticated responses with required fields and headers', function () {
        $response = (new AuthErrorResponseFactory)->unauthenticated('req-401');

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe([
                'code' => AuthTokenException::UNAUTHENTICATED,
                'message' => 'Authentication is required.',
                'request_id' => 'req-401',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
    });

    it('builds token restricted responses', function () {
        $response = (new AuthErrorResponseFactory)->tokenRestricted();

        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true)['code'])->toBe(AuthTokenException::TOKEN_RESTRICTED);
    });

    it('builds account status responses', function () {
        $factory = new AuthErrorResponseFactory;

        expect($factory->accountSuspended()->getData(true)['code'])
            ->toBe(AuthTokenException::ACCOUNT_SUSPENDED)
            ->and($factory->accountPendingDeletion()->getData(true)['code'])
            ->toBe(AuthTokenException::ACCOUNT_PENDING_DELETION);
    });

    it('builds resource not found responses', function () {
        $response = (new AuthErrorResponseFactory)->resourceNotFound();

        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true)['code'])->toBe(ResourceNotFoundException::RESOURCE_NOT_FOUND);
    });

    it('builds rate limit exceeded responses with Retry-After', function () {
        $response = (new AuthErrorResponseFactory)->rateLimitExceeded(42, 'req-429');

        expect($response->getStatusCode())->toBe(429)
            ->and($response->getData(true))->toBe([
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Too many requests.',
                'request_id' => 'req-429',
            ])
            ->and($response->headers->get('Retry-After'))->toBe('42')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('builds registration not allowed responses matching OpenAPI example fields', function () {
        $response = (new AuthErrorResponseFactory)->registrationNotAllowed('01K0C2Y7Q3R4S5T6V7W8X9Y0Z1');

        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true))->toBe([
                'code' => 'REGISTRATION_NOT_ALLOWED',
                'message' => 'Registration is not available for these details.',
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->getData(true))->not->toHaveKey('data')
            ->and($response->getData(true))->not->toHaveKey('token');
    });

    it('builds service unavailable responses matching OpenAPI example fields', function () {
        $response = (new AuthErrorResponseFactory)->serviceUnavailable('01K0C2Y7Q3R4S5T6V7W8X9Y0Z1');

        expect($response->getStatusCode())->toBe(503)
            ->and($response->getData(true))->toBe([
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'The service is temporarily unavailable.',
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});
