<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Modules\Links\Infrastructure\Telemetry\LinkQueryMetrics;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    app(LinkQueryMetrics::class)->reset();
});

function listTelemetryOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function listTelemetryBearer(UserModel $user): string
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
function getListLinksTelemetry(array $query = [], array $headers = []): TestResponse
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

function listTelemetryMetrics(): LinkQueryMetrics
{
    return app(LinkQueryMetrics::class);
}

/**
 * @return list<MessageLogged>
 */
function captureListTelemetryLogs(callable $callback): array
{
    /** @var list<MessageLogged> $captured */
    $captured = [];

    Log::listen(function (MessageLogged $event) use (&$captured): void {
        $captured[] = $event;
    });

    $callback();

    return $captured;
}

/**
 * @param  list<MessageLogged>  $logs
 */
function listTelemetrySinkBlob(array $logs, LinkQueryMetrics $metrics): string
{
    $parts = [
        json_encode($metrics->recorded(), JSON_THROW_ON_ERROR),
        json_encode($metrics->traces(), JSON_THROW_ON_ERROR),
    ];

    foreach ($logs as $event) {
        $parts[] = $event->message;
        $parts[] = json_encode($event->context, JSON_THROW_ON_ERROR);
    }

    return implode("\n", $parts);
}

describe('GET /api/v1/links query telemetry', function () {
    it('records list success without sensitive labels', function () {
        $user = listTelemetryOwner();
        $bearer = listTelemetryBearer($user);

        getListLinksTelemetry([], ['Authorization' => 'Bearer '.$bearer])->assertOk();

        $recorded = listTelemetryMetrics()->recorded();
        $last = $recorded[array_key_last($recorded)];

        expect($last['metric'])->toBe('links.query')
            ->and($last['labels'])->toBe([
                'operation' => LinkQueryMetrics::OPERATION_LIST,
                'result' => LinkQueryMetrics::RESULT_SUCCESS,
            ])
            ->and(array_keys($last['labels']))->not->toContain('token')
            ->and(array_keys($last['labels']))->not->toContain('cursor')
            ->and(array_keys($last['labels']))->not->toContain('slug')
            ->and(array_keys($last['labels']))->not->toContain('title')
            ->and(array_keys($last['labels']))->not->toContain('destination');
    });

    it('records validation_failed for invalid query parameters', function () {
        $user = listTelemetryOwner();
        $bearer = listTelemetryBearer($user);

        getListLinksTelemetry(['per_page' => '0'], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422);

        expect(listTelemetryMetrics()->recorded()[0]['labels'])->toBe([
            'operation' => LinkQueryMetrics::OPERATION_LIST,
            'result' => LinkQueryMetrics::RESULT_FAILURE,
            'reason' => LinkQueryMetrics::REASON_VALIDATION_FAILED,
        ]);
    });

    it('records invalid_cursor for a tampered cursor', function () {
        $user = listTelemetryOwner();
        $bearer = listTelemetryBearer($user);

        getListLinksTelemetry(['cursor' => 'not-a-cursor'], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422)
            ->assertJsonPath('errors.cursor.0.code', 'INVALID_CURSOR');

        $reasons = array_map(
            static fn (array $entry): ?string => $entry['labels']['reason'] ?? null,
            listTelemetryMetrics()->recorded(),
        );

        expect($reasons)->toContain(LinkQueryMetrics::REASON_INVALID_CURSOR);
    });

    it('records rate_limited when the private-read budget is exceeded', function () {
        $user = listTelemetryOwner();
        $issued = app(IssueAuthToken::class)->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
        );
        $key = (new LinkRateLimitKeyFactory)->forPrivateRead($issued->tokenId);
        $maxAttempts = (int) config('links.rate_limits.private_read.max_attempts', 300);
        $decaySeconds = (int) config('links.rate_limits.private_read.decay_seconds', 60);

        RateLimiter::clear($key);

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decaySeconds);
        }

        listTelemetryMetrics()->reset();

        getListLinksTelemetry([], ['Authorization' => 'Bearer '.$issued->plainTextToken])
            ->assertStatus(429);

        expect(listTelemetryMetrics()->recorded()[0]['labels']['reason'])
            ->toBe(LinkQueryMetrics::REASON_RATE_LIMITED);
    });

    it('records infrastructure when the rate-limit driver fails open', function () {
        $user = listTelemetryOwner();
        $bearer = listTelemetryBearer($user);

        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('redis connection refused'));

        getListLinksTelemetry([], ['Authorization' => 'Bearer '.$bearer])->assertOk();

        $reasons = array_map(
            static fn (array $entry): ?string => $entry['labels']['reason'] ?? null,
            listTelemetryMetrics()->recorded(),
        );

        expect($reasons)->toContain(LinkQueryMetrics::REASON_INFRASTRUCTURE);
    });

    it('sentinel-proves token, cursor, slug, title and destination never reach log, metric, or trace sinks', function () {
        $markerSlug = 'sentinel-slug-marker9f3a';
        $markerTitle = 'SENTINEL_TITLE_marker_9f3a';
        $markerUrl = 'https://example.com/SENTINEL_URL_marker_9f3a?q=SENTINEL_QUERY_marker#SENTINEL_FRAGMENT_marker';
        $markerCursor = 'SENTINEL_CURSOR_marker_9f3a';

        $user = listTelemetryOwner();
        $bearer = listTelemetryBearer($user);

        // @phpstan-ignore method.notFound
        test()->postJson('/api/v1/links', [
            'destination_url' => $markerUrl,
            'custom_alias' => $markerSlug,
            'title' => $markerTitle,
        ], ['Authorization' => 'Bearer '.$bearer])->assertCreated();

        listTelemetryMetrics()->reset();

        $logs = captureListTelemetryLogs(function () use ($bearer, $markerCursor): void {
            getListLinksTelemetry([], ['Authorization' => 'Bearer '.$bearer])->assertOk();
            getListLinksTelemetry(['cursor' => $markerCursor], ['Authorization' => 'Bearer '.$bearer])
                ->assertStatus(422);
        });

        $metrics = listTelemetryMetrics();
        $blob = listTelemetrySinkBlob($logs, $metrics);

        foreach ([
            $bearer,
            $markerSlug,
            $markerTitle,
            'SENTINEL_URL_marker_9f3a',
            'SENTINEL_QUERY_marker',
            'SENTINEL_FRAGMENT_marker',
            $markerCursor,
        ] as $marker) {
            expect($blob)->not->toContain($marker);
        }

        foreach ($metrics->recorded() as $entry) {
            expect(array_keys($entry['labels']))->not->toContain('token')
                ->and(array_keys($entry['labels']))->not->toContain('cursor')
                ->and(array_keys($entry['labels']))->not->toContain('slug')
                ->and(array_keys($entry['labels']))->not->toContain('title')
                ->and(array_keys($entry['labels']))->not->toContain('destination')
                ->and(array_keys($entry['labels']))->not->toContain('destination_url');
        }
    });

    it('rejects unsupported operations and failure reasons on the metrics component', function () {
        expect(fn () => listTelemetryMetrics()->recordSuccess('not-an-operation'))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => listTelemetryMetrics()->recordFailure(
                LinkQueryMetrics::OPERATION_LIST,
                'not_a_real_reason',
            ))->toThrow(InvalidArgumentException::class);
    });
});
