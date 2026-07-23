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
});
