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
use Modules\Links\Infrastructure\Telemetry\LinkQueryMetrics;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    app(LinkQueryMetrics::class)->reset();
});

function getLinkTelemetryOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function getLinkTelemetryBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getLinkTelemetryDetail(string $linkId, array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->getJson('/api/v1/links/'.$linkId, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

describe('GET /api/v1/links/{link} query telemetry', function () {
    it('records detail success without sensitive labels', function () {
        $user = getLinkTelemetryOwner();
        $bearer = getLinkTelemetryBearer($user);

        // @phpstan-ignore method.notFound
        $created = test()->postJson('/api/v1/links', [
            'destination_url' => 'https://example.com/telemetry-detail',
            'custom_alias' => 'telem-dtl',
            'title' => 'Telemetry Detail',
        ], ['Authorization' => 'Bearer '.$bearer]);
        $created->assertCreated();

        app(LinkQueryMetrics::class)->reset();

        getLinkTelemetryDetail($created->json('data.id'), ['Authorization' => 'Bearer '.$bearer])->assertOk();

        $recorded = app(LinkQueryMetrics::class)->recorded();
        $last = $recorded[array_key_last($recorded)];

        expect($last['metric'])->toBe('links.query')
            ->and($last['labels'])->toBe([
                'operation' => LinkQueryMetrics::OPERATION_DETAIL,
                'result' => LinkQueryMetrics::RESULT_SUCCESS,
            ]);
    });

    it('records not_found for a missing owner-scoped link', function () {
        $user = getLinkTelemetryOwner();
        $bearer = getLinkTelemetryBearer($user);

        getLinkTelemetryDetail(
            '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99',
            ['Authorization' => 'Bearer '.$bearer],
        )->assertNotFound();

        expect(app(LinkQueryMetrics::class)->recorded()[0]['labels'])->toBe([
            'operation' => LinkQueryMetrics::OPERATION_DETAIL,
            'result' => LinkQueryMetrics::RESULT_FAILURE,
            'reason' => LinkQueryMetrics::REASON_NOT_FOUND,
        ]);
    });

    it('records decrypt_failed when the destination envelope cannot be opened', function () {
        $user = getLinkTelemetryOwner();
        $bearer = getLinkTelemetryBearer($user);

        // @phpstan-ignore method.notFound
        $created = test()->postJson('/api/v1/links', [
            'destination_url' => 'https://example.com/telemetry-corrupt',
            'custom_alias' => 'telem-crp',
        ], ['Authorization' => 'Bearer '.$bearer]);
        $created->assertCreated();

        DB::table('link_destination_versions')
            ->where('short_link_id', $created->json('data.id'))
            ->update(['destination_url' => '!!!not-a-valid-envelope!!!']);

        app(LinkQueryMetrics::class)->reset();

        getLinkTelemetryDetail($created->json('data.id'), ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(503);

        expect(app(LinkQueryMetrics::class)->recorded()[0]['labels']['reason'])
            ->toBe(LinkQueryMetrics::REASON_DECRYPT_FAILED);
    });

    it('sentinel-proves token, slug, title and destination never reach log, metric, or trace sinks', function () {
        $markerSlug = 'sentinel-detail-slug9f3b';
        $markerTitle = 'SENTINEL_DETAIL_TITLE_9f3b';
        $markerUrl = 'https://example.com/SENTINEL_DETAIL_URL_9f3b';

        $user = getLinkTelemetryOwner();
        $bearer = getLinkTelemetryBearer($user);

        // @phpstan-ignore method.notFound
        $created = test()->postJson('/api/v1/links', [
            'destination_url' => $markerUrl,
            'custom_alias' => $markerSlug,
            'title' => $markerTitle,
        ], ['Authorization' => 'Bearer '.$bearer]);
        $created->assertCreated();

        app(LinkQueryMetrics::class)->reset();

        /** @var list<MessageLogged> $captured */
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });

        getLinkTelemetryDetail($created->json('data.id'), ['Authorization' => 'Bearer '.$bearer])->assertOk();

        $metrics = app(LinkQueryMetrics::class);
        $parts = [
            json_encode($metrics->recorded(), JSON_THROW_ON_ERROR),
            json_encode($metrics->traces(), JSON_THROW_ON_ERROR),
        ];

        foreach ($captured as $event) {
            $parts[] = $event->message;
            $parts[] = json_encode($event->context, JSON_THROW_ON_ERROR);
        }

        $blob = implode("\n", $parts);

        foreach ([$bearer, $markerSlug, $markerTitle, 'SENTINEL_DETAIL_URL_9f3b'] as $marker) {
            expect($blob)->not->toContain($marker);
        }
    });
});
