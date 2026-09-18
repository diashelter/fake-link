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
use Modules\Links\Exceptions\InvalidCursor;
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

function listLinksContractOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function listLinksContractBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

/**
 * @param  array<string, scalar>  $query
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getListLinksContract(array $query = [], array $headers = []): TestResponse
{
    $uri = '/api/v1/links';

    if ($query !== []) {
        $uri .= '?'.http_build_query($query);
    }

    // @phpstan-ignore method.notFound
    $response = test()->getJson($uri, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

describe('Contract: GET /api/v1/links', function () {
    it('returns 200 LinkCollection matching OpenAPI', function () {
        $user = listLinksContractOwner();
        $bearer = listLinksContractBearer($user);

        // @phpstan-ignore method.notFound
        test()->postJson('/api/v1/links', [
            'destination_url' => 'https://example.com/contract-list',
            'custom_alias' => 'contract-list',
            'title' => 'Contract List',
            'expires_at' => null,
        ], ['Authorization' => 'Bearer '.$bearer])->assertCreated();

        $response = getListLinksContract([], ['Authorization' => 'Bearer '.$bearer]);
        $response->assertOk();

        $body = $response->json();
        expect($body)->toBeArray();

        OpenApiSchemaAssert::assertMatchesSchema(
            $body,
            OpenApiDocument::load()->responseSchema('LinkCollection'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        $data = $response->json('data.0');
        expect($data)->toBeArray();
        OpenApiSchemaAssert::assertMatchesSchema(
            $data,
            OpenApiDocument::load()->schema('LinkSummary'),
        );

        $withDestination = $data;
        $withDestination['destination_url'] = 'https://example.com/leaked';
        $rejected = false;

        try {
            OpenApiSchemaAssert::assertMatchesSchema(
                $withDestination,
                OpenApiDocument::load()->schema('LinkSummary'),
            );
        } catch (Throwable) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue()
            ->and($response->headers->get('ETag'))->toBeNull();
    });

    it('returns 422 ValidationError matching OpenAPI for an invalid per_page', function () {
        $user = listLinksContractOwner();
        $bearer = listLinksContractBearer($user);

        $response = getListLinksContract(['per_page' => '0'], ['Authorization' => 'Bearer '.$bearer]);
        $response->assertStatus(422);

        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('ValidationError'),
        );

        expect($response->json('code'))->toBe(LinksOpenApiCatalog::VALIDATION_FAILED)
            ->and($response->json('message'))->toBe(LinksOpenApiCatalog::message(LinksOpenApiCatalog::VALIDATION_FAILED))
            ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('returns 422 ValidationError matching OpenAPI for INVALID_CURSOR', function () {
        $user = listLinksContractOwner();
        $bearer = listLinksContractBearer($user);

        $response = getListLinksContract(['cursor' => 'not-a-cursor'], ['Authorization' => 'Bearer '.$bearer]);
        $response->assertStatus(422);

        OpenApiSchemaAssert::assertMatchesSchema(
            $response->json(),
            OpenApiDocument::load()->responseSchema('ValidationError'),
        );
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);

        expect($response->json('code'))->toBe(LinksOpenApiCatalog::VALIDATION_FAILED)
            ->and($response->json('errors.cursor.0.code'))->toBe(InvalidCursor::ERROR_CODE)
            ->and($response->json('errors.cursor.0.message'))->toBe('The cursor is invalid or has expired.');
    });

    it('returns 429 TooManyRequests matching OpenAPI when the private-read limit is exceeded', function () {
        $user = listLinksContractOwner();
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

        $response = getListLinksContract([], ['Authorization' => 'Bearer '.$issued->plainTextToken]);

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
