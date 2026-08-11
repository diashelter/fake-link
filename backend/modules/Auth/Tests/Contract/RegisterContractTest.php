<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\Tests\Support\OpenApi\AuthOpenApiCatalog;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Auth\Tests\Support\OpenApi\OpenApiSchemaAssert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    OpenApiDocument::clearCache();

    $this->clientIp = '203.0.113.'.random_int(1, 254);
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forRegistrationIp($this->clientIp));
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registerContractPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'ValidPass1!xy',
        'password_confirmation' => 'ValidPass1!xy',
        'accept_terms' => true,
    ], $overrides);
}

describe('Contract: POST /api/v1/auth/register', function () {
    it('returns 201 AuthResponse matching OpenAPI AuthIssued schema', function () {
        Queue::fake();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerContractPayload());

        $response->assertCreated();

        $payload = $response->json();
        expect($payload)->toBeArray();

        OpenApiSchemaAssert::assertMatchesSchema(
            $payload,
            OpenApiDocument::load()->responseSchema('AuthIssued'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 403 REGISTRATION_NOT_ALLOWED error envelope for non-allowlisted email', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerContractPayload([
                'email' => 'stranger@example.com',
            ]));

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            403,
            AuthOpenApiCatalog::REGISTRATION_NOT_ALLOWED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::REGISTRATION_NOT_ALLOWED),
        );
    });
});
