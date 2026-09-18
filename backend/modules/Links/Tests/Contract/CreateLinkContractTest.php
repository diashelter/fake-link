<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Auth\Tests\Support\OpenApi\OpenApiSchemaAssert;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Modules\Links\Tests\Support\OpenApi\LinksOpenApiCatalog;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    OpenApiDocument::clearCache();
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

function createLinkContractOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function createLinkContractBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function postCreateLinkContract(array $payload = [], array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

/**
 * Full CreateLinkRequest shape (optional keys present) so additionalProperties:false
 * exact-key asserts from OpenApiSchemaAssert can pass.
 *
 * @return array<string, mixed>
 */
function createLinkContractValidPayload(): array
{
    return [
        'destination_url' => 'https://example.com/contract-create',
        'custom_alias' => 'contract-alias',
        'title' => 'Contract Link',
        'expires_at' => null,
    ];
}

describe('Contract: POST /api/v1/links', function () {
    it('accepts a CreateLinkRequest payload that matches the OpenAPI schema', function () {
        OpenApiSchemaAssert::assertMatchesSchema(
            createLinkContractValidPayload(),
            OpenApiDocument::load()->schema('CreateLinkRequest'),
        );
    });

    it('returns 201 LinkResponse matching OpenAPI with LinkCreated headers', function () {
        $user = createLinkContractOwner();
        $bearer = createLinkContractBearer($user);
        $payload = createLinkContractValidPayload();

        OpenApiSchemaAssert::assertMatchesSchema(
            $payload,
            OpenApiDocument::load()->schema('CreateLinkRequest'),
        );

        $response = postCreateLinkContract($payload, [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertCreated();

        $body = $response->json();
        expect($body)->toBeArray();

        OpenApiSchemaAssert::assertMatchesSchema(
            $body,
            OpenApiDocument::load()->responseSchema('LinkCreated'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        expect($response->headers->get('Location'))->toMatch('#^/api/v1/links/[0-9a-f-]{36}$#i')
            ->and($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{64}"$/');
    });

    it('returns a 201 body whose data has no properties outside LinkDetail', function () {
        $user = createLinkContractOwner();
        $bearer = createLinkContractBearer($user);

        $response = postCreateLinkContract(
            [
                'destination_url' => 'https://example.com/contract-detail-keys',
                'custom_alias' => 'detail-keys',
                'title' => null,
                'expires_at' => null,
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated();

        $data = $response->json('data');
        expect($data)->toBeArray();

        $linkDetailSchema = OpenApiDocument::load()->schema('LinkDetail');

        OpenApiSchemaAssert::assertMatchesSchema($data, $linkDetailSchema);

        $withExtra = $data;
        $withExtra['version'] = 1;

        $rejected = false;

        try {
            OpenApiSchemaAssert::assertMatchesSchema($withExtra, $linkDetailSchema);
        } catch (Throwable) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue();
    });

    it('returns 409 LinkConflict matching OpenAPI for an unavailable alias', function () {
        $user = createLinkContractOwner();
        $bearer = createLinkContractBearer($user);

        postCreateLinkContract(
            [
                'destination_url' => 'https://example.com/first',
                'custom_alias' => 'taken-contract',
                'title' => null,
                'expires_at' => null,
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $response = postCreateLinkContract(
            [
                'destination_url' => 'https://example.com/second',
                'custom_alias' => 'Taken-Contract',
                'title' => null,
                'expires_at' => null,
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            409,
            LinksOpenApiCatalog::ALIAS_UNAVAILABLE,
            LinksOpenApiCatalog::message(LinksOpenApiCatalog::ALIAS_UNAVAILABLE),
        );
        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('LinkConflict'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 422 ValidationError matching OpenAPI for an invalid payload', function () {
        $user = createLinkContractOwner();
        $bearer = createLinkContractBearer($user);

        $response = postCreateLinkContract(
            [],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertStatus(422);

        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('ValidationError'),
        );

        $requestId = $response->json('request_id');

        expect($response->json('code'))->toBe(LinksOpenApiCatalog::VALIDATION_FAILED)
            ->and($response->json('message'))->toBe(LinksOpenApiCatalog::message(LinksOpenApiCatalog::VALIDATION_FAILED))
            ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
        expect($requestId)->toBeString();
        expect($requestId)->not->toBe('');
    });

    it('returns 429 TooManyRequests matching OpenAPI when the create limit is exceeded', function () {
        $user = createLinkContractOwner();
        $bearer = createLinkContractBearer($user);
        $userId = UserId::fromString($user->id);
        $key = (new LinkRateLimitKeyFactory)->forLinkCreation($userId);

        RateLimiter::clear($key);

        $maxAttempts = (int) config('links.rate_limits.create.max_attempts', 60);
        $decay = (int) config('links.rate_limits.create.decay_seconds', 60);

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decay);
        }

        $response = postCreateLinkContract(
            [
                'destination_url' => 'https://example.com/rate-limited-contract',
                'custom_alias' => null,
                'title' => null,
                'expires_at' => null,
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            429,
            LinksOpenApiCatalog::RATE_LIMIT_EXCEEDED,
            LinksOpenApiCatalog::message(LinksOpenApiCatalog::RATE_LIMIT_EXCEEDED),
        );
        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('TooManyRequests'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
    });
});
