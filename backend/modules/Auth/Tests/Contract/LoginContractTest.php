<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
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
    $this->knownPassword = 'ValidPass1!xy';

    $keyFactory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($keyFactory->forLoginIp($this->clientIp));
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function loginContractPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'login@example.com',
        'password' => 'ValidPass1!xy',
    ], $overrides);
}

describe('Contract: POST /api/v1/auth/login', function () {
    it('returns 200 AuthResponse session matching OpenAPI AuthIssued schema with private cache headers', function () {
        UserModel::factory()
            ->active()
            ->withPassword($this->knownPassword)
            ->create([
                'email' => 'contract.login@example.com',
                'name' => 'Contract Login',
            ]);

        RateLimiter::clear((new HmacRateLimitKeyFactory)->forLoginEmailIp(
            $this->clientIp,
            'contract.login@example.com',
        ));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginContractPayload([
                'email' => 'contract.login@example.com',
                'password' => $this->knownPassword,
            ]));

        $response->assertOk();

        $payload = $response->json();
        expect($payload)->toBeArray()
            ->and($payload['data']['token_kind'])->toBe('session');

        OpenApiSchemaAssert::assertMatchesSchema(
            $payload,
            OpenApiDocument::load()->responseSchema('AuthIssued'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 401 INVALID_CREDENTIALS error envelope for unknown email', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginContractPayload([
                'email' => 'missing.contract@example.com',
                'password' => $this->knownPassword,
            ]));

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            401,
            AuthOpenApiCatalog::INVALID_CREDENTIALS,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::INVALID_CREDENTIALS),
        );
    });
});
