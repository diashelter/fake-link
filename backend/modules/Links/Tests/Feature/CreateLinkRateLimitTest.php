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
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Modules\Links\UseCases\CreateLink;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function rateLimitOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function rateLimitBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function clearLinkCreationLimit(UserId $userId): void
{
    RateLimiter::clear((new LinkRateLimitKeyFactory)->forLinkCreation($userId));
}

function linkCreationAttempts(UserId $userId): int
{
    return RateLimiter::attempts((new LinkRateLimitKeyFactory)->forLinkCreation($userId));
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function postCreateLinkRateLimited(array $payload = [], array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

final class RateLimitExhaustionSlugSource implements RandomSlugSource
{
    public function candidate(int $length, string $alphabet): string
    {
        return 'exhstrl1';
    }
}

describe('POST /api/v1/links rate limit', function () {
    it('allows 60 authenticated requests and returns 429 on the 61st with Retry-After', function () {
        $user = rateLimitOwner();
        $userId = UserId::fromString($user->id);
        $bearer = rateLimitBearer($user);
        clearLinkCreationLimit($userId);

        expect(config('links.rate_limits.create.max_attempts'))->toBe(60)
            ->and(config('links.rate_limits.create.decay_seconds'))->toBe(60);

        for ($i = 1; $i <= 60; $i++) {
            $response = postCreateLinkRateLimited(
                ['destination_url' => 'https://example.com/rl-'.$i],
                ['Authorization' => 'Bearer '.$bearer],
            );

            expect($response->json('code'))->not->toBe('RATE_LIMIT_EXCEEDED', "request {$i} should not be rate limited");
        }

        $limited = postCreateLinkRateLimited(
            ['destination_url' => 'https://example.com/rl-61'],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
            ->and($limited->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('consumes quota for 422 validation failures', function () {
        $user = rateLimitOwner();
        $userId = UserId::fromString($user->id);
        $bearer = rateLimitBearer($user);
        clearLinkCreationLimit($userId);

        postCreateLinkRateLimited([], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422);

        expect(linkCreationAttempts($userId))->toBe(1);
    });

    it('consumes quota for 409 alias conflicts', function () {
        $user = rateLimitOwner();
        $userId = UserId::fromString($user->id);
        $bearer = rateLimitBearer($user);
        clearLinkCreationLimit($userId);

        SlugReservationModel::query()->create([
            'slug' => 'conflict-rl',
            'reserved_at' => now(),
        ]);

        postCreateLinkRateLimited(
            [
                'destination_url' => 'https://example.com/conflict',
                'custom_alias' => 'conflict-rl',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(409);

        expect(linkCreationAttempts($userId))->toBe(1);
    });

    it('consumes quota for 503 slug generation exhaustion', function () {
        config(['links.slug.max_collision_attempts' => 1]);
        app()->forgetInstance(CreateLink::class);
        app()->bind(RandomSlugSource::class, fn () => new RateLimitExhaustionSlugSource);

        SlugReservationModel::query()->create([
            'slug' => 'exhstrl1',
            'reserved_at' => now(),
        ]);

        $user = rateLimitOwner();
        $userId = UserId::fromString($user->id);
        $bearer = rateLimitBearer($user);
        clearLinkCreationLimit($userId);

        postCreateLinkRateLimited(
            ['destination_url' => 'https://example.com/exhaust'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(503);

        expect(linkCreationAttempts($userId))->toBe(1);
    });

    it('does not consume any account quota for invalid bearer', function () {
        $user = rateLimitOwner();
        $userId = UserId::fromString($user->id);
        clearLinkCreationLimit($userId);

        postCreateLinkRateLimited(
            ['destination_url' => 'https://example.com/noauth'],
            ['Authorization' => 'Bearer not-a-token'],
        )->assertUnauthorized();

        expect(linkCreationAttempts($userId))->toBe(0);
    });

    it('keeps independent counters for distinct accounts', function () {
        $first = rateLimitOwner();
        $second = rateLimitOwner();
        $firstId = UserId::fromString($first->id);
        $secondId = UserId::fromString($second->id);
        $firstBearer = rateLimitBearer($first);
        $secondBearer = rateLimitBearer($second);
        clearLinkCreationLimit($firstId);
        clearLinkCreationLimit($secondId);

        config(['links.rate_limits.create.max_attempts' => 2]);

        postCreateLinkRateLimited(
            [
                'destination_url' => 'https://example.com/a1',
                'custom_alias' => 'acct-a-1',
            ],
            ['Authorization' => 'Bearer '.$firstBearer],
        )->assertCreated();

        postCreateLinkRateLimited(
            [
                'destination_url' => 'https://example.com/a2',
                'custom_alias' => 'acct-a-2',
            ],
            ['Authorization' => 'Bearer '.$firstBearer],
        )->assertCreated();

        postCreateLinkRateLimited(
            [
                'destination_url' => 'https://example.com/a3',
                'custom_alias' => 'acct-a-3',
            ],
            ['Authorization' => 'Bearer '.$firstBearer],
        )->assertStatus(429);

        postCreateLinkRateLimited(
            [
                'destination_url' => 'https://example.com/b1',
                'custom_alias' => 'acct-b-1',
            ],
            ['Authorization' => 'Bearer '.$secondBearer],
        )->assertCreated();

        expect(linkCreationAttempts($secondId))->toBe(1)
            ->and(linkCreationAttempts($firstId))->toBeGreaterThanOrEqual(2);
    });

    it('builds an HMAC key that never contains the raw user id', function () {
        $userId = UserId::fromString((string) Str::uuid7());
        $key = (new LinkRateLimitKeyFactory)->forLinkCreation($userId);
        $hmacSecret = (string) config('links.rate_limit_hmac_key');
        $expected = hash_hmac('sha256', 'links:create:'.$userId->value(), $hmacSecret);

        expect($key)->toBe($expected)
            ->and($key)->toMatch('/^[a-f0-9]{64}$/')
            ->and($key)->not->toContain($userId->value())
            ->and($key)->not->toContain('links:create:');
    });

    it('fails open and emits a limiter metric when the rate limit driver is unavailable', function () {
        $user = rateLimitOwner();
        $bearer = rateLimitBearer($user);

        /** @var list<MessageLogged> $captured */
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });

        RateLimiter::shouldReceive('tooManyAttempts')
            ->once()
            ->andThrow(new RuntimeException('redis connection refused'));

        $response = postCreateLinkRateLimited(
            [
                'destination_url' => 'https://example.com/fail-open',
                'custom_alias' => 'fail-open-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated();

        $metric = collect($captured)->first(
            fn (MessageLogged $event): bool => $event->message === 'links.rate_limit.driver_unavailable',
        );

        expect($metric)->not->toBeNull()
            ->and($metric->level)->toBe('warning')
            ->and($metric->context['limiter'] ?? null)->toBe('links.create')
            ->and(json_encode($metric->context, JSON_THROW_ON_ERROR))->not->toContain($user->id);
    });
});
