<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Links\Infrastructure\Http\Controllers\GetLinkController;
use Modules\Links\Infrastructure\Http\Controllers\ListLinksController;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    OpenApiDocument::clearCache();
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

/**
 * @return array<string, mixed>
 */
function linkQueryOpenApiDocument(): array
{
    $fromEnv = getenv('OPENAPI_SPEC_PATH');
    $path = is_string($fromEnv) && $fromEnv !== ''
        ? $fromEnv
        : '/var/www/docs/openapi.yaml';
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile($path);

    return $document;
}

describe('Contract: link query surface', function () {
    it('keeps OpenAPI listLinks and getLink operations aligned with registered Laravel GET routes', function () {
        $document = linkQueryOpenApiDocument();
        $paths = $document['paths'] ?? [];

        expect($paths)->toBeArray()
            ->and($paths['/api/v1/links']['get']['operationId'] ?? null)->toBe('listLinks')
            ->and($paths['/api/v1/links/{link}']['get']['operationId'] ?? null)->toBe('getLink')
            ->and($paths['/api/v1/links']['get']['x-allowed-token-kinds'] ?? null)->toBe(['session'])
            ->and($paths['/api/v1/links/{link}']['get']['x-allowed-token-kinds'] ?? null)->toBe(['session']);

        $list = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/links',
        );
        $detail = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => in_array('GET', $route->methods(), true) && $route->uri() === 'api/v1/links/{link}',
        );

        expect($list)->not->toBeNull()
            ->and($detail)->not->toBeNull()
            ->and($list->getActionName())->toContain(ListLinksController::class)
            ->and($detail->getActionName())->toContain(GetLinkController::class)
            ->and($list->gatherMiddleware())->toContain('auth.bearer')
            ->and($list->gatherMiddleware())->toContain('token.kind:session')
            ->and($detail->gatherMiddleware())->toContain('auth.bearer')
            ->and($detail->gatherMiddleware())->toContain('token.kind:session');
    });

    it('exposes the private-read rate limit and cursor HMAC config required by the contract', function () {
        expect(config('links.rate_limits.private_read.max_attempts'))->toBe(300)
            ->and(config('links.rate_limits.private_read.decay_seconds'))->toBe(60)
            ->and((string) config('links.cursor_hmac_key'))->not->toBe('');
    });

    it('documents LinkCollection and LinkDetail response schemas used by the delivered GETs', function () {
        $collection = OpenApiDocument::load()->responseSchema('LinkCollection');
        $detail = OpenApiDocument::load()->responseSchema('LinkDetail');

        expect($collection)->toBeArray()
            ->and($detail)->toBeArray();

        OpenApiDocument::load()->schema('LinkSummary');
        OpenApiDocument::load()->schema('LinkDetail');
    });
});
