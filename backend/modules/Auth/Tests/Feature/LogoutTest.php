<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    $this->clientIp = '203.0.113.'.random_int(1, 254);
});

function issueSessionBearerForLogout(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueVerificationBearerForLogout(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function clearPrivateAuthWriteLimitForLogout(UserId $userId): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPrivateAuthWrite($userId));
}

describe('POST /api/v1/auth/logout', function () {
    it('revokes only the presented session token leaving the other intact', function () {
        $user = UserModel::factory()->active()->create(['email' => 'logout.dual@example.com']);
        $tokenA = issueSessionBearerForLogout($user);
        $tokenB = issueSessionBearerForLogout($user);
        clearPrivateAuthWriteLimitForLogout(UserId::fromString($user->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/logout', [], [
                'Authorization' => 'Bearer '.$tokenA,
            ]);

        $response->assertNoContent();
        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull();

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$tokenA,
        ])->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$tokenB,
        ])->assertOk();
    });

    it('allows verification tokens to logout with 204', function () {
        $user = UserModel::factory()->create(['email' => 'logout.verify@example.com']);
        $bearer = issueVerificationBearerForLogout($user);
        clearPrivateAuthWriteLimitForLogout(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertNoContent();

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertUnauthorized();
    });

    it('returns 401 on a second logout with the same bearer', function () {
        $user = UserModel::factory()->active()->create(['email' => 'logout.twice@example.com']);
        $bearer = issueSessionBearerForLogout($user);
        clearPrivateAuthWriteLimitForLogout(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertNoContent();

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });

    it('returns 422 VALIDATION_FAILED for extra body fields', function () {
        $user = UserModel::factory()->active()->create(['email' => 'logout.extra@example.com']);
        $bearer = issueSessionBearerForLogout($user);
        clearPrivateAuthWriteLimitForLogout(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/logout', [
            'extra' => 'nope',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->json('request_id'))->not->toBeNull();

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();
    });

    it('returns 401 UNAUTHENTICATED when the bearer is missing', function () {
        $this->postJson('/api/v1/auth/logout', [])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });

    it('returns 429 RATE_LIMIT_EXCEEDED when private write throttle is exceeded', function () {
        $user = UserModel::factory()->active()->create(['email' => 'logout.throttle@example.com']);
        $bearer = issueSessionBearerForLogout($user);
        $userId = UserId::fromString($user->id);
        clearPrivateAuthWriteLimitForLogout($userId);

        config(['auth.rate_limits.private_auth_write.max_attempts' => 1]);

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertNoContent();

        $secondBearer = issueSessionBearerForLogout($user);

        $limited = $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$secondBearer,
        ]);

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');
        expect($limited->headers->get('Retry-After'))->not->toBeNull();
    });
});
