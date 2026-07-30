<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
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
    $this->knownPassword = 'ValidPass1!xy';
});

function issueSessionBearerForLogoutAll(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueVerificationBearerForLogoutAll(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function clearPrivateAuthWriteLimitForLogoutAll(UserId $userId): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPrivateAuthWrite($userId));
}

describe('POST /api/v1/auth/logout-all', function () {
    it('revokes all bearers when current password matches', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'logout.all.ok@example.com',
            'name' => 'Logout All',
        ]);
        $tokenA = issueSessionBearerForLogoutAll($user);
        $tokenB = issueSessionBearerForLogoutAll($user);
        clearPrivateAuthWriteLimitForLogoutAll(UserId::fromString($user->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/logout-all', [
                'current_password' => $this->knownPassword,
            ], [
                'Authorization' => 'Bearer '.$tokenA,
            ]);

        $response->assertNoContent();
        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(0);

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$tokenA,
        ])->assertUnauthorized();

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$tokenB,
        ])->assertUnauthorized();

        $fresh = UserModel::query()->find($user->id);
        expect($fresh?->status)->toBe(UserStatus::Active->value)
            ->and($fresh?->name)->toBe('Logout All')
            ->and($fresh?->email)->toBe('logout.all.ok@example.com');
    });

    it('returns 401 INVALID_CREDENTIALS for wrong password without revoking tokens', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'logout.all.wrong@example.com',
        ]);
        $bearer = issueSessionBearerForLogoutAll($user);
        clearPrivateAuthWriteLimitForLogoutAll(UserId::fromString($user->id));
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $user->id)->count();

        $this->postJson('/api/v1/auth/logout-all', [
            'current_password' => 'WrongCurrent1!xx',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('message', 'The provided credentials are invalid.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe($tokenCountBefore);

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();
    });

    it('returns 403 TOKEN_RESTRICTED for a verification bearer', function () {
        $user = UserModel::factory()->create([
            'email' => 'logout.all.verify@example.com',
        ]);
        $bearer = issueVerificationBearerForLogoutAll($user);
        clearPrivateAuthWriteLimitForLogoutAll(UserId::fromString($user->id));
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $user->id)->count();

        $this->postJson('/api/v1/auth/logout-all', [
            'current_password' => $this->knownPassword,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'TOKEN_RESTRICTED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe($tokenCountBefore);
    });

    it('returns 422 VALIDATION_FAILED when current_password is missing or extras are present', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'logout.all.validation@example.com',
        ]);
        $bearer = issueSessionBearerForLogoutAll($user);
        clearPrivateAuthWriteLimitForLogoutAll(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/logout-all', [], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->postJson('/api/v1/auth/logout-all', [
            'current_password' => $this->knownPassword,
            'extra' => 'nope',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();
    });

    it('returns 401 UNAUTHENTICATED when the bearer is missing', function () {
        $this->postJson('/api/v1/auth/logout-all', [
            'current_password' => $this->knownPassword,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });
});
