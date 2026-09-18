<?php

declare(strict_types=1);

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\Output\PersistedShortLink;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Modules\Links\UseCases\CreateLink;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    app(LinkCreationMetrics::class)->reset();
});

function telemetryOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function telemetryBearer(UserModel $user): string
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
function postCreateLinkTelemetry(array $payload = [], array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

function telemetryMetrics(): LinkCreationMetrics
{
    return app(LinkCreationMetrics::class);
}

/**
 * @return list<MessageLogged>
 */
function captureTelemetryLogs(callable $callback): array
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
function telemetrySinkBlob(array $logs, LinkCreationMetrics $metrics): string
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

final class TelemetryCreateLinkFixedSlugSource implements RandomSlugSource
{
    public function __construct(private readonly string $candidate) {}

    public function candidate(int $length, string $alphabet): string
    {
        return $this->candidate;
    }
}

describe('POST /api/v1/links creation telemetry', function () {
    it('records success without sensitive labels', function () {
        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        postCreateLinkTelemetry(
            [
                'destination_url' => 'https://example.com/telemetry-ok',
                'custom_alias' => 'telemetry-ok',
                'title' => 'Telemetry Title',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $recorded = telemetryMetrics()->recorded();
        $last = $recorded[array_key_last($recorded)];

        expect($last['metric'])->toBe('links.create')
            ->and($last['labels'])->toBe(['result' => LinkCreationMetrics::RESULT_SUCCESS])
            ->and(array_keys($last['labels']))->not->toContain('slug')
            ->and(array_keys($last['labels']))->not->toContain('alias')
            ->and(array_keys($last['labels']))->not->toContain('custom_alias')
            ->and(array_keys($last['labels']))->not->toContain('destination_url')
            ->and(array_keys($last['labels']))->not->toContain('title');
    });

    it('records alias_unavailable on conflict', function () {
        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        postCreateLinkTelemetry(
            [
                'destination_url' => 'https://example.com/first',
                'custom_alias' => 'taken-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        telemetryMetrics()->reset();

        postCreateLinkTelemetry(
            [
                'destination_url' => 'https://example.com/second',
                'custom_alias' => 'Taken-Alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(409);

        expect(telemetryMetrics()->recorded()[0]['labels'])->toBe([
            'result' => LinkCreationMetrics::RESULT_FAILURE,
            'reason' => LinkCreationMetrics::REASON_ALIAS_UNAVAILABLE,
        ]);
    });

    it('records slug_exhausted when generation cannot allocate', function () {
        config(['links.slug.max_collision_attempts' => 2]);

        app()->forgetInstance(CreateLink::class);
        app()->bind(RandomSlugSource::class, fn () => new TelemetryCreateLinkFixedSlugSource('stucktel'));

        SlugReservationModel::query()->create([
            'slug' => 'stucktel',
            'reserved_at' => now(),
        ]);

        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        postCreateLinkTelemetry(
            ['destination_url' => 'https://example.com/exhaust'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(503);

        expect(telemetryMetrics()->recorded()[0]['labels']['reason'])
            ->toBe(LinkCreationMetrics::REASON_SLUG_EXHAUSTED);
    });

    it('records validation_failed for invalid payloads', function () {
        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        postCreateLinkTelemetry(
            [],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422);

        expect(telemetryMetrics()->recorded()[0]['labels']['reason'])
            ->toBe(LinkCreationMetrics::REASON_VALIDATION_FAILED);
    });

    it('records validation_failed for reserved-word aliases', function () {
        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        postCreateLinkTelemetry(
            [
                'destination_url' => 'https://example.com/ok',
                'custom_alias' => 'ADMIN',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422);

        $reasons = array_map(
            static fn (array $entry): string => $entry['labels']['reason'],
            telemetryMetrics()->recorded(),
        );

        expect($reasons)->toContain(LinkCreationMetrics::REASON_VALIDATION_FAILED);
    });

    it('records rate_limited when the create budget is exceeded', function () {
        $user = telemetryOwner();
        $userId = UserId::fromString($user->id);
        $bearer = telemetryBearer($user);
        $key = (new LinkRateLimitKeyFactory)->forLinkCreation($userId);
        $maxAttempts = (int) config('links.rate_limits.create.max_attempts', 60);
        $decaySeconds = (int) config('links.rate_limits.create.decay_seconds', 60);

        RateLimiter::clear($key);

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decaySeconds);
        }

        telemetryMetrics()->reset();

        postCreateLinkTelemetry(
            ['destination_url' => 'https://example.com/limited'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(429);

        expect(telemetryMetrics()->recorded()[0]['labels']['reason'])
            ->toBe(LinkCreationMetrics::REASON_RATE_LIMITED);
    });

    it('records infrastructure when CreateLink throws unexpectedly', function () {
        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        app()->bind(ShortLinkRepository::class, fn () => new class implements ShortLinkRepository
        {
            public function create(
                UserId $ownerId,
                Slug $slug,
                SlugSource $slugSource,
                ?string $title,
                ?DateTimeImmutable $expiresAt,
            ): PersistedShortLink {
                throw new RuntimeException('simulated infrastructure failure');
            }
        });
        app()->forgetInstance(CreateLink::class);

        postCreateLinkTelemetry(
            [
                'destination_url' => 'https://example.com/infra',
                'custom_alias' => 'infra-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(503);

        expect(telemetryMetrics()->recorded()[0]['labels']['reason'])
            ->toBe(LinkCreationMetrics::REASON_INFRASTRUCTURE);
    });

    it('records infrastructure when the rate-limit driver fails open', function () {
        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('redis connection refused'));

        postCreateLinkTelemetry(
            [
                'destination_url' => 'https://example.com/fail-open',
                'custom_alias' => 'fail-open-tel',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $reasons = array_map(
            static fn (array $entry): ?string => $entry['labels']['reason'] ?? null,
            telemetryMetrics()->recorded(),
        );

        expect($reasons)->toContain(LinkCreationMetrics::REASON_INFRASTRUCTURE);
    });

    it('sentinel-proves marker values never reach log, metric, or trace sinks', function () {
        $markerAlias = 'sentinel-alias-marker9f3a';
        $markerUrl = 'https://example.com/SENTINEL_URL_marker_9f3a?q=SENTINEL_QUERY_marker#SENTINEL_FRAGMENT_marker';
        $markerTitle = 'SENTINEL_TITLE_marker_9f3a';

        $user = telemetryOwner();
        $bearer = telemetryBearer($user);

        $logs = captureTelemetryLogs(function () use ($bearer, $markerAlias, $markerUrl, $markerTitle): void {
            postCreateLinkTelemetry(
                [
                    'destination_url' => $markerUrl,
                    'custom_alias' => $markerAlias,
                    'title' => $markerTitle,
                ],
                ['Authorization' => 'Bearer '.$bearer],
            )->assertCreated();
        });

        $metrics = telemetryMetrics();
        $blob = telemetrySinkBlob($logs, $metrics);

        foreach ([
            $markerAlias,
            'SENTINEL_URL_marker_9f3a',
            'SENTINEL_QUERY_marker',
            'SENTINEL_FRAGMENT_marker',
            $markerTitle,
        ] as $marker) {
            expect($blob)->not->toContain($marker);
        }

        foreach ($metrics->recorded() as $entry) {
            expect(array_keys($entry['labels']))->not->toContain('slug')
                ->and(array_keys($entry['labels']))->not->toContain('alias')
                ->and(array_keys($entry['labels']))->not->toContain('custom_alias')
                ->and(array_keys($entry['labels']))->not->toContain('destination_url')
                ->and(array_keys($entry['labels']))->not->toContain('query')
                ->and(array_keys($entry['labels']))->not->toContain('fragment')
                ->and(array_keys($entry['labels']))->not->toContain('title');
        }

        foreach ($metrics->traces() as $trace) {
            expect(array_keys($trace['attributes']))->not->toContain('slug')
                ->and(array_keys($trace['attributes']))->not->toContain('destination_url')
                ->and(array_keys($trace['attributes']))->not->toContain('title');
        }
    });

    it('rejects unsupported failure reasons on the metrics component', function () {
        expect(fn () => telemetryMetrics()->recordFailure('not_a_real_reason'))
            ->toThrow(InvalidArgumentException::class);
    });
});
