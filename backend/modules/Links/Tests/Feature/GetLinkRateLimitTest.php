<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\DTOs\Output\IssuedAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function getLinkRateOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function getLinkRateIssue(UserModel $user): IssuedAuthTokenDto
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    );
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getLinkDetailRateLimited(string $linkId, array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->getJson('/api/v1/links/'.$linkId, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

describe('GET /api/v1/links/{link} rate limit', function () {
    it('shares the 300/min per-token private-read limiter with GET /api/v1/links', function () {
        $user = getLinkRateOwner();
        $issued = getLinkRateIssue($user);
        $headers = ['Authorization' => 'Bearer '.$issued->plainTextToken];

        // @phpstan-ignore method.notFound
        $created = test()->postJson('/api/v1/links', [
            'destination_url' => 'https://example.com/rate-detail',
            'custom_alias' => 'rate-dtl1',
        ], $headers);
        $created->assertCreated();

        $key = (new LinkRateLimitKeyFactory)->forPrivateRead($issued->tokenId);
        RateLimiter::clear($key);
        config(['links.rate_limits.private_read.max_attempts' => 2]);

        // @phpstan-ignore method.notFound
        test()->getJson('/api/v1/links', $headers)->assertOk();
        getLinkDetailRateLimited($created->json('data.id'), $headers)->assertOk();
        getLinkDetailRateLimited($created->json('data.id'), $headers)
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect((int) RateLimiter::attempts($key))->toBeGreaterThanOrEqual(2);
    });

    it('returns Retry-After when the shared private-read budget is exhausted', function () {
        $user = getLinkRateOwner();
        $issued = getLinkRateIssue($user);
        $key = (new LinkRateLimitKeyFactory)->forPrivateRead($issued->tokenId);

        RateLimiter::clear($key);
        $maxAttempts = (int) config('links.rate_limits.private_read.max_attempts');
        $decay = (int) config('links.rate_limits.private_read.decay_seconds');

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decay);
        }

        $limited = getLinkDetailRateLimited(
            '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99',
            ['Authorization' => 'Bearer '.$issued->plainTextToken],
        );

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
            ->and($limited->headers->get('Cache-Control'))->toContain('no-store');
    });
});
