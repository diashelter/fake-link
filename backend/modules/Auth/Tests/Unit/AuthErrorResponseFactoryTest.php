<?php

declare(strict_types=1);

use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Exceptions\EmailAlreadyVerifiedException;
use Modules\Auth\Exceptions\InvalidCredentialsException;
use Modules\Auth\Exceptions\InvalidVerificationTokenException;
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

    it('builds invalid credentials responses matching OpenAPI example fields', function () {
        expect(InvalidCredentialsException::invalid()->errorCode())
            ->toBe(InvalidCredentialsException::INVALID_CREDENTIALS);

        $response = (new AuthErrorResponseFactory)->invalidCredentials('01K0C2Y7Q3R4S5T6V7W8X9Y0Z1');

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe([
                'code' => InvalidCredentialsException::INVALID_CREDENTIALS,
                'message' => 'The provided credentials are invalid.',
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->getData(true))->not->toHaveKey('data')
            ->and($response->getData(true))->not->toHaveKey('token')
            ->and($response->getData(true))->not->toHaveKey('user');
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

    it('builds invalid verification token responses with exact OpenAPI-aligned fields', function () {
        expect(InvalidVerificationTokenException::invalid()->errorCode())
            ->toBe(InvalidVerificationTokenException::INVALID_VERIFICATION_TOKEN);

        $response = (new AuthErrorResponseFactory)->invalidVerificationToken('01K0C2Y7Q3R4S5T6V7W8X9Y0Z1');

        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true))->toBe([
                'code' => 'INVALID_VERIFICATION_TOKEN',
                'message' => 'The verification token is invalid or has expired.',
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
    });

    it('builds email already verified responses with exact OpenAPI-aligned fields', function () {
        expect(EmailAlreadyVerifiedException::alreadyVerified()->errorCode())
            ->toBe(EmailAlreadyVerifiedException::EMAIL_ALREADY_VERIFIED);

        $response = (new AuthErrorResponseFactory)->emailAlreadyVerified('01K0C2Y7Q3R4S5T6V7W8X9Y0Z1');

        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true))->toBe([
                'code' => 'EMAIL_ALREADY_VERIFIED',
                'message' => 'The email address is already verified.',
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
    });

    it('does not include sentinel plaintext in verification exception or error messages', function () {
        $sentinel = 'SENTINEL-PLAINTEXT-TOKEN-MARKER-12345';

        expect(InvalidVerificationTokenException::invalid()->getMessage())->not->toContain($sentinel)
            ->and(EmailAlreadyVerifiedException::alreadyVerified()->getMessage())->not->toContain($sentinel)
            ->and((new AuthErrorResponseFactory)->invalidVerificationToken()->getData(true)['message'])
            ->not->toContain($sentinel)
            ->and((new AuthErrorResponseFactory)->emailAlreadyVerified()->getData(true)['message'])
            ->not->toContain($sentinel);
    });
});
