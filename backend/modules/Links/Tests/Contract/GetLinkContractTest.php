<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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

function getLinkContractOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function getLinkContractBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getLinkContractDetail(string $linkId, array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->getJson('/api/v1/links/'.$linkId, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

describe('Contract: GET /api/v1/links/{link}', function () {
    it('returns 200 LinkDetail matching OpenAPI with a strong ETag', function () {
        $user = getLinkContractOwner();
        $bearer = getLinkContractBearer($user);

        // @phpstan-ignore method.notFound
        $created = test()->postJson('/api/v1/links', [
            'destination_url' => 'https://example.com/contract-detail',
            'custom_alias' => 'contract-dt',
            'title' => 'Contract Detail',
            'expires_at' => null,
        ], ['Authorization' => 'Bearer '.$bearer]);
        $created->assertCreated();

        $response = getLinkContractDetail($created->json('data.id'), [
            'Authorization' => 'Bearer '.$bearer,
        ]);
        $response->assertOk();

        $body = $response->json();
        expect($body)->toBeArray();

        OpenApiSchemaAssert::assertMatchesSchema(
            $body,
            OpenApiDocument::load()->responseSchema('LinkDetail'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        $data = $response->json('data');
        expect($data)->toBeArray();
        OpenApiSchemaAssert::assertMatchesSchema(
            $data,
            OpenApiDocument::load()->schema('LinkDetail'),
        );

        expect($response->headers->get('ETag'))->toBe($created->headers->get('ETag'))
            ->and($data)->not->toHaveKey('version')
            ->and($data)->not->toHaveKey('blocked_at')
            ->and($data)->not->toHaveKey('user_id');
    });

    it('returns 404 NotFound matching OpenAPI for a missing link', function () {
        $user = getLinkContractOwner();
        $bearer = getLinkContractBearer($user);

        $response = getLinkContractDetail(
            '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99',
            ['Authorization' => 'Bearer '.$bearer],
        );

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            404,
            LinksOpenApiCatalog::RESOURCE_NOT_FOUND,
            LinksOpenApiCatalog::message(LinksOpenApiCatalog::RESOURCE_NOT_FOUND),
        );
        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('NotFound'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        expect($response->headers->get('ETag'))->toBeNull()
            ->and($response->json())->not->toHaveKey('data');
    });

    it('returns 503 ServiceUnavailable matching OpenAPI when destination decryption fails', function () {
        $user = getLinkContractOwner();
        $bearer = getLinkContractBearer($user);

        // @phpstan-ignore method.notFound
        $created = test()->postJson('/api/v1/links', [
            'destination_url' => 'https://example.com/contract-corrupt',
            'custom_alias' => 'contract-cr',
            'title' => null,
            'expires_at' => null,
        ], ['Authorization' => 'Bearer '.$bearer]);
        $created->assertCreated();

        DB::table('link_destination_versions')
            ->where('short_link_id', $created->json('data.id'))
            ->update(['destination_url' => '!!!not-a-valid-envelope!!!']);

        $response = getLinkContractDetail($created->json('data.id'), [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            503,
            LinksOpenApiCatalog::SERVICE_UNAVAILABLE,
            LinksOpenApiCatalog::message(LinksOpenApiCatalog::SERVICE_UNAVAILABLE),
        );
        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('ServiceUnavailable'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        expect($response->headers->get('ETag'))->toBeNull()
            ->and($response->json())->not->toHaveKey('data');
    });

    it('returns 429 TooManyRequests matching OpenAPI when the private-read limit is exceeded', function () {
        $user = getLinkContractOwner();
        $issued = app(IssueAuthToken::class)->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
        );
        $key = (new LinkRateLimitKeyFactory)->forPrivateRead($issued->tokenId);

        RateLimiter::clear($key);

        $maxAttempts = (int) config('links.rate_limits.private_read.max_attempts', 300);
        $decay = (int) config('links.rate_limits.private_read.decay_seconds', 60);

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decay);
        }

        $response = getLinkContractDetail(
            '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99',
            ['Authorization' => 'Bearer '.$issued->plainTextToken],
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
