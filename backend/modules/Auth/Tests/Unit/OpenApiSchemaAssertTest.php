<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Tests\Support\OpenApi\AuthOpenApiCatalog;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Auth\Tests\Support\OpenApi\OpenApiSchemaAssert;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    OpenApiDocument::clearCache();
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function makeOpenApiTestResponse(array $payload, int $status = 200, array $headers = []): TestResponse
{
    $response = new JsonResponse($payload, $status);

    foreach ($headers as $name => $value) {
        $response->headers->set($name, $value);
    }

    return TestResponse::fromBaseResponse($response);
}

describe('AuthOpenApiCatalog', function () {
    it('lists at least the eleven ABMC-08 Auth error codes with OpenAPI messages', function () {
        $required = [
            'INVALID_CREDENTIALS',
            'TOKEN_RESTRICTED',
            'REGISTRATION_NOT_ALLOWED',
            'INVALID_VERIFICATION_TOKEN',
            'EMAIL_ALREADY_VERIFIED',
            'PASSWORD_REUSED',
            'UNAUTHENTICATED',
            'ACCOUNT_SUSPENDED',
            'ACCOUNT_PENDING_DELETION',
            'VALIDATION_FAILED',
            'RATE_LIMIT_EXCEEDED',
        ];

        $codes = AuthOpenApiCatalog::errorCodes();
        $messages = AuthOpenApiCatalog::messages();

        expect($codes)->toHaveCount(11)
            ->and($codes)->toEqualCanonicalizing($required);

        foreach ($required as $code) {
            expect($messages)->toHaveKey($code)
                ->and($messages[$code])->toBeString()
                ->and($messages[$code])->not->toBe('');
        }

        expect(AuthOpenApiCatalog::message(AuthOpenApiCatalog::INVALID_CREDENTIALS))
            ->toBe('The provided credentials are invalid.')
            ->and(AuthOpenApiCatalog::message(AuthOpenApiCatalog::VALIDATION_FAILED))
            ->toBe('The given data was invalid.');
    });
});

describe('OpenApiSchemaAssert', function () {
    it('accepts payloads with exact keys and rejects an extra field', function () {
        OpenApiSchemaAssert::assertExactKeys(
            ['code' => 'X', 'message' => 'Y', 'request_id' => 'Z'],
            ['code', 'message', 'request_id'],
        );

        expect(fn () => OpenApiSchemaAssert::assertExactKeys(
            ['code' => 'X', 'message' => 'Y', 'request_id' => 'Z', 'extra' => true],
            ['code', 'message', 'request_id'],
        ))->toThrow(AssertionFailedError::class);
    });

    it('validates error envelope status code message and request_id', function () {
        $response = makeOpenApiTestResponse(
            [
                'code' => AuthOpenApiCatalog::UNAUTHENTICATED,
                'message' => AuthOpenApiCatalog::message(AuthOpenApiCatalog::UNAUTHENTICATED),
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ],
            401,
        );

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            401,
            AuthOpenApiCatalog::UNAUTHENTICATED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::UNAUTHENTICATED),
        );

        $wrongMessage = makeOpenApiTestResponse(
            [
                'code' => AuthOpenApiCatalog::UNAUTHENTICATED,
                'message' => 'Wrong message',
                'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ],
            401,
        );

        expect(fn () => OpenApiSchemaAssert::assertErrorEnvelope(
            $wrongMessage,
            401,
            AuthOpenApiCatalog::UNAUTHENTICATED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::UNAUTHENTICATED),
        ))->toThrow(AssertionFailedError::class);
    });

    it('validates private cache and request id headers and fails when missing', function () {
        $ok = makeOpenApiTestResponse(
            ['ok' => true],
            200,
            [
                'Cache-Control' => 'private, no-store',
                'X-Request-ID' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
            ],
        );

        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($ok);

        $missing = makeOpenApiTestResponse(['ok' => true], 200, [
            'Cache-Control' => 'private, no-store',
        ]);

        expect(fn () => OpenApiSchemaAssert::assertPrivateCacheAndRequestId($missing))
            ->toThrow(AssertionFailedError::class);
    });

    it('matches ErrorResponse schema for a valid payload and rejects unknown properties', function () {
        $schema = OpenApiDocument::load()->schema('ErrorResponse');

        $valid = [
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'The provided credentials are invalid.',
            'request_id' => '01K0C2Y7Q3R4S5T6V7W8X9Y0Z1',
        ];

        OpenApiSchemaAssert::assertMatchesSchema($valid, $schema);

        expect(fn () => OpenApiSchemaAssert::assertMatchesSchema(
            [
                ...$valid,
                'token' => 'leaked',
            ],
            $schema,
        ))->toThrow(AssertionFailedError::class);
    });
});
