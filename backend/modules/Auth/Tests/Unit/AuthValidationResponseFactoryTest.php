<?php

declare(strict_types=1);

use Modules\Auth\Exceptions\InvalidPasswordResetTokenException;
use Modules\Auth\Exceptions\PasswordReusedException;
use Modules\Auth\Infrastructure\Http\Responses\AuthValidationResponseFactory;
use Tests\TestCase;

uses(TestCase::class);

describe('AuthValidationResponseFactory', function () {
    it('builds 422 VALIDATION_FAILED for an invalid password reset token with the stable field message', function () {
        $response = (new AuthValidationResponseFactory)->invalidPasswordResetToken('req-token');
        $payload = $response->getData(true);

        expect($response->getStatusCode())->toBe(422)
            ->and($payload['code'])->toBe('VALIDATION_FAILED')
            ->and($payload['request_id'])->toBe('req-token')
            ->and($payload['errors']['token'][0]['code'])->toBe('INVALID')
            ->and($payload['errors']['token'][0]['message'])->toBe(InvalidPasswordResetTokenException::MESSAGE)
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('builds 422 VALIDATION_FAILED for password reused with PASSWORD_REUSED code', function () {
        $response = (new AuthValidationResponseFactory)->passwordReused('req-reused');
        $payload = $response->getData(true);

        expect($response->getStatusCode())->toBe(422)
            ->and($payload['code'])->toBe('VALIDATION_FAILED')
            ->and($payload['request_id'])->toBe('req-reused')
            ->and($payload['errors']['password'][0]['code'])->toBe(PasswordReusedException::PASSWORD_REUSED)
            ->and($payload['errors']['password'][0]['message'])->toBe(PasswordReusedException::MESSAGE)
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('does not include sentinel plaintext in validation error messages', function () {
        $sentinel = 'password-reset-token-sentinel-xyz';
        $tokenResponse = (new AuthValidationResponseFactory)->invalidPasswordResetToken();
        $reusedResponse = (new AuthValidationResponseFactory)->passwordReused();

        expect(json_encode($tokenResponse->getData(true)))->not->toContain($sentinel)
            ->and(json_encode($reusedResponse->getData(true)))->not->toContain($sentinel);
    });
});
