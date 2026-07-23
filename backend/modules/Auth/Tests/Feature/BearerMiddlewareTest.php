<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\RevokeAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function issueBearerToken(UserModel $user, TokenKind $kind = TokenKind::Session): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), $kind),
    )->plainTextToken;
}

describe('AuthenticateBearer middleware', function () {
    it('returns probe data for a valid bearer token', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user);

        $response = $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.token_kind', 'session');

        Carbon::setTestNow();
    });

    it('returns 401 when authorization header is missing', function () {
        $response = $this->getJson('/api/v1/_test/auth/probe');

        $response->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('returns 401 for lowercase bearer scheme', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user);

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'bearer '.$token,
        ])->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 401 for invalid tokens', function () {
        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer invalid-token',
        ])->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 401 for absolutely expired tokens without touching last_used_at', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user, TokenKind::Verification);

        Carbon::setTestNow('2026-01-02T00:00:00+00:00');

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized();

        expect(AuthTokenModel::query()->first()?->last_used_at)->toBeNull();

        Carbon::setTestNow();
    });

    it('returns 401 for idle expired tokens', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user, TokenKind::Verification);

        Carbon::setTestNow('2026-01-01T01:00:01+00:00');

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$token,
        ])->assertUnauthorized();

        Carbon::setTestNow();
    });

    it('returns 403 for suspended and deletion pending accounts', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');

        $suspended = UserModel::factory()->create(['status' => UserStatus::Suspended->value]);
        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.issueBearerToken($suspended),
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_SUSPENDED);

        $pending = UserModel::factory()->create(['status' => UserStatus::DeletionPending->value]);
        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.issueBearerToken($pending),
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_PENDING_DELETION);

        Carbon::setTestNow();
    });

    it('returns 401 after token revocation', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $issued = app(IssueAuthToken::class)->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
        );

        app(RevokeAuthToken::class)->byId($issued->tokenId);

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$issued->plainTextToken,
        ])->assertUnauthorized();
    });

    it('throttles last_used_at updates across http requests', function () {
        Carbon::setTestNow('2026-01-01T00:00:00+00:00');
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user);

        $this->getJson('/api/v1/_test/auth/probe', ['Authorization' => 'Bearer '.$token])->assertOk();

        Carbon::setTestNow('2026-01-01T00:10:00+00:00');
        $this->getJson('/api/v1/_test/auth/probe', ['Authorization' => 'Bearer '.$token])->assertOk();

        expect(AuthTokenModel::query()->first()?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T00:00:00+00:00');

        Carbon::setTestNow('2026-01-01T00:16:00+00:00');
        $this->getJson('/api/v1/_test/auth/probe', ['Authorization' => 'Bearer '.$token])->assertOk();

        expect(AuthTokenModel::query()->first()?->last_used_at?->toIso8601String())
            ->toBe('2026-01-01T00:16:00+00:00');

        Carbon::setTestNow();
    });
});

describe('RequireTokenKind middleware', function () {
    it('returns 403 when verification token accesses session-only route', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user, TokenKind::Verification);

        $this->getJson('/api/v1/_test/auth/session-only', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);
    });

    it('returns 403 when session token accesses verification-only route', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);
        $token = issueBearerToken($user, TokenKind::Session);

        $this->getJson('/api/v1/_test/auth/verification-only', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);
    });

    it('allows either kind on routes that accept both', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::Active->value]);

        $this->getJson('/api/v1/_test/auth/any-kind', [
            'Authorization' => 'Bearer '.issueBearerToken($user, TokenKind::Session),
        ])->assertOk();

        $this->getJson('/api/v1/_test/auth/any-kind', [
            'Authorization' => 'Bearer '.issueBearerToken($user, TokenKind::Verification),
        ])->assertOk();
    });

    it('returns 403 for session token with pending verification user', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::PendingVerification->value]);
        $token = issueBearerToken($user, TokenKind::Session);

        $this->getJson('/api/v1/_test/auth/session-only', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);
    });
});
