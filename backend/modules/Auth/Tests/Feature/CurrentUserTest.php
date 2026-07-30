<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
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

function issueSessionBearerForMe(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueVerificationBearerForMe(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function clearPrivateAuthWriteLimitForMe(UserId $userId): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPrivateAuthWrite($userId));
}

function clearPrivateAuthReadLimitForToken(string $plainTextToken): void
{
    $hash = hash('sha256', $plainTextToken);
    $row = AuthTokenModel::query()
        ->where('token_hash', $hash)
        ->firstOrFail();

    RateLimiter::clear(
        (new HmacRateLimitKeyFactory)->forPrivateAuthRead(AuthTokenId::fromString($row->id))
    );
}

describe('GET /api/v1/me', function () {
    it('returns UserResponse for a session bearer with OpenAPI fields and real timestamps', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Session User',
            'email' => 'me.session@example.com',
        ]);
        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthReadLimitForToken($bearer);
        $fresh = $user->fresh();

        $response = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Session User')
            ->assertJsonPath('data.email', 'me.session@example.com')
            ->assertJsonPath('data.status', UserStatus::Active->value)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'status',
                    'email_verified_at',
                    'terms_version',
                    'terms_accepted_at',
                    'created_at',
                    'updated_at',
                ],
            ]);

        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($response->json('data.created_at'))->toBe(
                $fresh->created_at->clone()->utc()->format('Y-m-d\TH:i:s\Z')
            )
            ->and($response->json('data.updated_at'))->toBe(
                $fresh->updated_at->clone()->utc()->format('Y-m-d\TH:i:s\Z')
            )
            ->and(array_keys($response->json('data')))->toBe([
                'id',
                'name',
                'email',
                'status',
                'email_verified_at',
                'terms_version',
                'terms_accepted_at',
                'created_at',
                'updated_at',
            ]);
    });

    it('returns pending_verification profile for a verification bearer', function () {
        $user = UserModel::factory()->create([
            'name' => 'Pending User',
            'email' => 'me.pending@example.com',
            'status' => UserStatus::PendingVerification->value,
            'email_verified_at' => null,
        ]);
        $bearer = issueVerificationBearerForMe($user);
        clearPrivateAuthReadLimitForToken($bearer);

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('data.email_verified_at', null);
    });

    it('returns 429 RATE_LIMIT_EXCEEDED when private read throttle is exceeded', function () {
        $user = UserModel::factory()->active()->create(['email' => 'me.throttle@example.com']);
        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthReadLimitForToken($bearer);
        config(['auth.rate_limits.private_auth_read.max_attempts' => 1]);

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();

        $limited = $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');
        expect($limited->headers->get('Retry-After'))->not->toBeNull();
    });

    it('returns 401 UNAUTHENTICATED when the bearer is missing', function () {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });
});

describe('PATCH /api/v1/me', function () {
    it('renames the user and advances updated_at', function () {
        Carbon::setTestNow('2026-07-30 12:00:00');
        $user = UserModel::factory()->active()->create([
            'name' => 'Old Name',
            'email' => 'me.rename@example.com',
        ]);
        $before = $user->fresh();
        Carbon::setTestNow('2026-07-30 12:00:05');

        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthWriteLimitForMe(UserId::fromString($user->id));

        $response = $this->patchJson('/api/v1/me', [
            'name' => 'Ana Silva',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.email', 'me.rename@example.com');

        $after = UserModel::query()->find($user->id);
        expect($after?->name)->toBe('Ana Silva')
            ->and($after?->updated_at->gt($before->updated_at))->toBeTrue();

        Carbon::setTestNow();
    });

    it('trims outer spaces from name before persisting', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Before',
            'email' => 'me.trim@example.com',
        ]);
        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthWriteLimitForMe(UserId::fromString($user->id));

        $this->patchJson('/api/v1/me', [
            'name' => '  Ana  ',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ana');

        expect(UserModel::query()->find($user->id)?->name)->toBe('Ana');
    });

    it('returns 200 without bumping updated_at on a no-op rename', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Same Name',
            'email' => 'me.noop@example.com',
        ]);
        $before = $user->fresh();
        $updatedAtBefore = $before->updated_at->toIso8601String();
        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthWriteLimitForMe(UserId::fromString($user->id));

        $this->patchJson('/api/v1/me', [
            'name' => 'Same Name',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Same Name');

        expect(UserModel::query()->find($user->id)?->updated_at?->toIso8601String())->toBe($updatedAtBefore);
    });

    it('returns 403 TOKEN_RESTRICTED for a verification bearer without changing name', function () {
        $user = UserModel::factory()->create([
            'name' => 'Pending Name',
            'email' => 'me.patch.verify@example.com',
        ]);
        $bearer = issueVerificationBearerForMe($user);
        clearPrivateAuthWriteLimitForMe(UserId::fromString($user->id));

        $this->patchJson('/api/v1/me', [
            'name' => 'Hacked',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'TOKEN_RESTRICTED');

        expect(UserModel::query()->find($user->id)?->name)->toBe('Pending Name');
    });

    it('returns 422 for extra fields or email without mutating email', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Keep Name',
            'email' => 'me.immutable@example.com',
        ]);
        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthWriteLimitForMe(UserId::fromString($user->id));

        $this->patchJson('/api/v1/me', [
            'name' => 'New Name',
            'email' => 'attacker@example.com',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $fresh = UserModel::query()->find($user->id);
        expect($fresh?->email)->toBe('me.immutable@example.com')
            ->and($fresh?->name)->toBe('Keep Name');
    });

    it('returns 422 when name is empty after trim', function () {
        $user = UserModel::factory()->active()->create([
            'name' => 'Keep',
            'email' => 'me.empty@example.com',
        ]);
        $bearer = issueSessionBearerForMe($user);
        clearPrivateAuthWriteLimitForMe(UserId::fromString($user->id));

        $this->patchJson('/api/v1/me', [
            'name' => '   ',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        expect(UserModel::query()->find($user->id)?->name)->toBe('Keep');
    });
});
