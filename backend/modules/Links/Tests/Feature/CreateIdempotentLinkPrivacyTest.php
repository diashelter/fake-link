<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Domain\Services\CanonicalCreateLinkCommand;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\Infrastructure\Telemetry\LinkCreationMetrics;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    app(LinkCreationMetrics::class)->reset();
});

function privacyIdemOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function privacyIdemBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

/**
 * @return list<MessageLogged>
 */
function capturePrivacyIdemLogs(callable $callback): array
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
function privacyIdemSinkBlob(array $logs, LinkCreationMetrics $metrics): string
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

function privacyIdempotencyTablePlaintext(): string
{
    $row = DB::table('idempotency_keys')->first();

    if ($row === null) {
        return '';
    }

    $parts = [];

    foreach ((array) $row as $column => $value) {
        if ($column === 'response_snapshot') {
            continue;
        }

        $parts[] = is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }

    return implode('|', $parts);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function postPrivacyIdemLink(array $payload, array $headers): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    return $response;
}

describe('idempotency privacy sentinels', function () {
    it('keeps raw key, URL markers and fingerprint out of idempotency rows, logs, metrics and traces', function () {
        $user = privacyIdemOwner();
        $bearer = privacyIdemBearer($user);

        $rawKey = 'sentinel-raw-idem-key-9f3a';
        $markerUrl = 'https://example.com/SENTINEL_URL_idem_9f3a?q=SENTINEL_QUERY_idem#SENTINEL_FRAGMENT_idem';
        $markerTitle = 'SENTINEL_TITLE_idem_9f3a';

        $canonical = app(CanonicalCreateLinkCommand::class);
        $ownerId = UserId::fromString($user->id);
        $input = new CreateLinkInput(
            destinationUrl: $markerUrl,
            customAlias: null,
            title: $markerTitle,
            expiresAt: null,
        );
        $fingerprint = $canonical->fingerprint($ownerId, $input);

        $logs = capturePrivacyIdemLogs(function () use ($bearer, $rawKey, $markerUrl, $markerTitle): void {
            postPrivacyIdemLink([
                'destination_url' => $markerUrl,
                'title' => $markerTitle,
            ], [
                'Authorization' => 'Bearer '.$bearer,
                'Idempotency-Key' => $rawKey,
            ])->assertCreated();
        });

        $sink = privacyIdemSinkBlob($logs, app(LinkCreationMetrics::class));
        $idemPlain = privacyIdempotencyTablePlaintext();

        foreach ([
            $rawKey,
            'SENTINEL_URL_idem_9f3a',
            'SENTINEL_QUERY_idem',
            'SENTINEL_FRAGMENT_idem',
            $markerTitle,
        ] as $marker) {
            expect($sink)->not->toContain($marker)
                ->and($idemPlain)->not->toContain($marker);
        }

        expect($sink)->not->toContain($fingerprint)
            ->and(DB::table('idempotency_keys')->value('request_fingerprint'))->toBe($fingerprint)
            ->and(DB::table('short_links')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1);
    });

    it('returns 503 without Location, ETag or destination leakage when the snapshot is tampered', function () {
        $user = privacyIdemOwner();
        $bearer = privacyIdemBearer($user);
        $headers = [
            'Authorization' => 'Bearer '.$bearer,
            'Idempotency-Key' => 'idem-key-tamper-abcdef',
        ];
        $payload = ['destination_url' => 'https://example.com/idem-tamper-sentinel'];

        postPrivacyIdemLink($payload, $headers)->assertCreated();

        DB::update(
            "UPDATE idempotency_keys SET response_snapshot = decode(?, 'hex')",
            [bin2hex(random_bytes(64))],
        );

        $failed = postPrivacyIdemLink($payload, $headers);

        $failed->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE');

        // @phpstan-ignore staticMethod.dynamicCall
        $body = $failed->getContent();

        expect($failed->headers->get('Location'))->toBeNull()
            ->and($failed->headers->get('ETag'))->toBeNull()
            ->and($body)->not->toContain('https://example.com/idem-tamper-sentinel')
            ->and($body)->not->toContain('idem-key-tamper')
            ->and(DB::table('short_links')->count())->toBe(1);
    });
});
