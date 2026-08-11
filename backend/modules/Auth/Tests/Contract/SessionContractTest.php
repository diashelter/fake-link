<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\Tests\Support\OpenApi\AuthOpenApiCatalog;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Auth\Tests\Support\OpenApi\OpenApiSchemaAssert;
use Modules\Auth\UseCases\IssueAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    OpenApiDocument::clearCache();

    $this->clientIp = '203.0.113.'.random_int(1, 254);
    $this->knownPassword = 'ValidPass1!xy';
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

function issueSessionBearerForSessionContract(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueVerificationBearerForSessionContract(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function clearPrivateAuthWriteLimitForSessionContract(UserId $userId): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPrivateAuthWrite($userId));
}

function clearPrivateAuthReadLimitForSessionContract(string $plainTextToken): void
{
    $hash = hash('sha256', $plainTextToken);
    $row = AuthTokenModel::query()
        ->where('token_hash', $hash)
        ->firstOrFail();

    RateLimiter::clear(
        (new HmacRateLimitKeyFactory)->forPrivateAuthRead(AuthTokenId::fromString($row->id))
    );
}

describe('Contract: GET /api/v1/me', function () {
    it('returns 200 UserResponse with exact OpenAPI data keys and private cache headers', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Session Contract User',
            'email' => 'contract.me@example.com',
        ]);
        $bearer = issueSessionBearerForSessionContract($user);
        clearPrivateAuthReadLimitForSessionContract($bearer);

        $response = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertOk();

        $payload = $response->json();
        expect($payload)->toBeArray();

        OpenApiSchemaAssert::assertMatchesSchema(
            $payload,
            OpenApiDocument::load()->responseSchema('User'),
        );
        OpenApiSchemaAssert::assertExactKeys(
            $payload['data'],
            [
                'id',
                'name',
                'email',
                'status',
                'email_verified_at',
                'terms_version',
                'terms_accepted_at',
                'created_at',
                'updated_at',
            ],
            '$.data',
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });
});

describe('Contract: PATCH /api/v1/me', function () {
    it('returns 403 TOKEN_RESTRICTED for verification bearer', function () {
        $user = UserModel::factory()->create([
            'email' => 'contract.me.patch.verify@example.com',
        ]);
        $bearer = issueVerificationBearerForSessionContract($user);
        clearPrivateAuthWriteLimitForSessionContract(UserId::fromString($user->id));

        $response = $this->patchJson('/api/v1/me', [
            'name' => 'Blocked Name',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            403,
            AuthOpenApiCatalog::TOKEN_RESTRICTED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::TOKEN_RESTRICTED),
        );
    });
});

describe('Contract: POST /api/v1/auth/logout', function () {
    it('returns 204 No Content on happy path', function () {
        $user = UserModel::factory()->active()->create([
            'email' => 'contract.logout@example.com',
        ]);
        $bearer = issueSessionBearerForSessionContract($user);
        clearPrivateAuthWriteLimitForSessionContract(UserId::fromString($user->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/logout', [], [
                'Authorization' => 'Bearer '.$bearer,
            ]);

        $response->assertNoContent();
        expect($response->getContent())->toBe('');
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 401 UNAUTHENTICATED when bearer is missing', function () {
        $response = $this->postJson('/api/v1/auth/logout', []);

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            401,
            AuthOpenApiCatalog::UNAUTHENTICATED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::UNAUTHENTICATED),
        );
    });
});

describe('Contract: POST /api/v1/auth/logout-all', function () {
    it('returns 204 No Content on happy path', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'contract.logout.all@example.com',
        ]);
        $bearer = issueSessionBearerForSessionContract($user);
        clearPrivateAuthWriteLimitForSessionContract(UserId::fromString($user->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/logout-all', [
                'current_password' => $this->knownPassword,
            ], [
                'Authorization' => 'Bearer '.$bearer,
            ]);

        $response->assertNoContent();
        expect($response->getContent())->toBe('');
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 401 UNAUTHENTICATED when bearer is missing', function () {
        $response = $this->postJson('/api/v1/auth/logout-all', [
            'current_password' => $this->knownPassword,
        ]);

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            401,
            AuthOpenApiCatalog::UNAUTHENTICATED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::UNAUTHENTICATED),
        );
    });
});
