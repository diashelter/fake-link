<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
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

function listReadRateLimitOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function listReadRateLimitIssue(UserModel $user): IssuedAuthTokenDto
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    );
}

function clearPrivateReadLimit(AuthTokenId $tokenId): void
{
    RateLimiter::clear((new LinkRateLimitKeyFactory)->forPrivateRead($tokenId));
}

function privateReadAttempts(AuthTokenId $tokenId): int
{
    return RateLimiter::attempts((new LinkRateLimitKeyFactory)->forPrivateRead($tokenId));
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getListLinksRateLimited(array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->getJson('/api/v1/links', $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

describe('GET /api/v1/links rate limit', function () {
    it('returns 429 RATE_LIMIT_EXCEEDED with Retry-After when the token exceeds 300 reads per minute', function () {
        $user = listReadRateLimitOwner();
        $issued = listReadRateLimitIssue($user);
        $key = (new LinkRateLimitKeyFactory)->forPrivateRead($issued->tokenId);

        expect(config('links.rate_limits.private_read.max_attempts'))->toBe(300)
            ->and(config('links.rate_limits.private_read.decay_seconds'))->toBe(60);

        RateLimiter::clear($key);
        $maxAttempts = (int) config('links.rate_limits.private_read.max_attempts');
        $decay = (int) config('links.rate_limits.private_read.decay_seconds');

        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decay);
        }

        $limited = getListLinksRateLimited(['Authorization' => 'Bearer '.$issued->plainTextToken]);

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
            ->and($limited->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('keeps independent counters for distinct tokens of the same account', function () {
        $user = listReadRateLimitOwner();
        $first = listReadRateLimitIssue($user);
        $second = listReadRateLimitIssue($user);

        config(['links.rate_limits.private_read.max_attempts' => 2]);
        clearPrivateReadLimit($first->tokenId);
        clearPrivateReadLimit($second->tokenId);

        getListLinksRateLimited(['Authorization' => 'Bearer '.$first->plainTextToken])->assertOk();
        getListLinksRateLimited(['Authorization' => 'Bearer '.$first->plainTextToken])->assertOk();
        getListLinksRateLimited(['Authorization' => 'Bearer '.$first->plainTextToken])
            ->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        getListLinksRateLimited(['Authorization' => 'Bearer '.$second->plainTextToken])->assertOk();

        expect(privateReadAttempts($second->tokenId))->toBe(1)
            ->and(privateReadAttempts($first->tokenId))->toBeGreaterThanOrEqual(2);
    });

    it('does not consume any token quota for an invalid bearer', function () {
        $user = listReadRateLimitOwner();
        $issued = listReadRateLimitIssue($user);
        clearPrivateReadLimit($issued->tokenId);

        getListLinksRateLimited(['Authorization' => 'Bearer not-a-token'])
            ->assertUnauthorized();

        expect(privateReadAttempts($issued->tokenId))->toBe(0);
    });

    it('consumes quota for 422 validation failures', function () {
        $user = listReadRateLimitOwner();
        $issued = listReadRateLimitIssue($user);
        clearPrivateReadLimit($issued->tokenId);

        // @phpstan-ignore method.notFound
        test()->getJson('/api/v1/links?per_page=0', [
            'Authorization' => 'Bearer '.$issued->plainTextToken,
        ])->assertStatus(422);

        expect(privateReadAttempts($issued->tokenId))->toBe(1);
    });

    it('builds an HMAC key that never contains the raw token id', function () {
        $tokenId = AuthTokenId::fromString((string) Str::uuid7());
        $key = (new LinkRateLimitKeyFactory)->forPrivateRead($tokenId);
        $hmacSecret = (string) config('links.rate_limit_hmac_key');
        $expected = hash_hmac('sha256', 'links:private-read:'.$tokenId->value(), $hmacSecret);

        expect($key)->toBe($expected)
            ->and($key)->toMatch('/^[a-f0-9]{64}$/')
            ->and($key)->not->toContain($tokenId->value())
            ->and($key)->not->toContain('links:private-read:');
    });

    it('fails open and emits a limiter metric when the rate limit driver is unavailable', function () {
        $user = listReadRateLimitOwner();
        $issued = listReadRateLimitIssue($user);

        /** @var list<MessageLogged> $captured */
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });

        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('redis connection refused'));

        $response = getListLinksRateLimited(['Authorization' => 'Bearer '.$issued->plainTextToken]);
        $response->assertOk();

        $metric = collect($captured)->first(
            fn (MessageLogged $event): bool => $event->message === 'links.rate_limit.driver_unavailable',
        );

        expect($metric)->not->toBeNull()
            ->and($metric->level)->toBe('warning')
            ->and($metric->context['limiter'] ?? null)->toBe('links.private_read')
            ->and(json_encode($metric->context, JSON_THROW_ON_ERROR))->not->toContain($user->id)
            ->and(json_encode($metric->context, JSON_THROW_ON_ERROR))->not->toContain($issued->tokenId->value())
            ->and(json_encode($metric->context, JSON_THROW_ON_ERROR))->not->toContain($issued->plainTextToken);
    });
});
