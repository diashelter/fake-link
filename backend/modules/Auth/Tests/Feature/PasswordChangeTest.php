<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Exceptions\PasswordReusedException;
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
    $this->newPassword = 'BrandNewPass1!x';
});

function issueSessionBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueVerificationBearerForChange(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function clearPrivateAuthWriteLimit(UserId $userId): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPrivateAuthWrite($userId));
}

describe('POST /api/v1/auth/password/change', function () {
    it('changes password, revokes all bearers, and allows login with the new password', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'change.happy@example.com',
        ]);
        $bearer = issueSessionBearer($user);
        issueSessionBearer($user);
        clearPrivateAuthWriteLimit(UserId::fromString($user->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/change', [
                'current_password' => $this->knownPassword,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ], [
                'Authorization' => 'Bearer '.$bearer,
            ]);

        $response->assertNoContent();
        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull();

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(0);

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', [
                'email' => 'change.happy@example.com',
                'password' => $this->newPassword,
            ])
            ->assertOk()
            ->assertJsonPath('data.token_kind', 'session');
    });

    it('returns 401 INVALID_CREDENTIALS for a wrong current password without revoking tokens', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'change.wrong@example.com',
        ]);
        $bearer = issueSessionBearer($user);
        clearPrivateAuthWriteLimit(UserId::fromString($user->id));
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $user->id)->count();

        $response = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => 'WrongCurrent1!xx',
            'password' => $this->newPassword,
            'password_confirmation' => $this->newPassword,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('message', 'The provided credentials are invalid.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe($tokenCountBefore);
    });

    it('returns 403 TOKEN_RESTRICTED for a verification bearer', function () {
        $user = UserModel::factory()->create([
            'email' => 'change.verify@example.com',
        ]);
        $bearer = issueVerificationBearerForChange($user);
        clearPrivateAuthWriteLimit(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->knownPassword,
            'password' => $this->newPassword,
            'password_confirmation' => $this->newPassword,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'TOKEN_RESTRICTED');
    });

    it('returns 422 PASSWORD_REUSED without revoking tokens', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'change.reused@example.com',
        ]);
        $bearer = issueSessionBearer($user);
        clearPrivateAuthWriteLimit(UserId::fromString($user->id));
        // @phpstan-ignore staticMethod.dynamicCall
        $tokenCountBefore = AuthTokenModel::query()->where('user_id', $user->id)->count();

        $response = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->knownPassword,
            'password' => $this->knownPassword,
            'password_confirmation' => $this->knownPassword,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.password.0.code', PasswordReusedException::PASSWORD_REUSED)
            ->assertJsonPath('errors.password.0.message', PasswordReusedException::MESSAGE);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe($tokenCountBefore)
            ->and(UserModel::query()->find($user->id)?->status)->toBe(UserStatus::Active->value);
    });

    it('returns 401 UNAUTHENTICATED when the bearer is missing', function () {
        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->knownPassword,
            'password' => $this->newPassword,
            'password_confirmation' => $this->newPassword,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    });

    it('returns 422 VALIDATION_FAILED for extra fields', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create();
        $bearer = issueSessionBearer($user);
        clearPrivateAuthWriteLimit(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->knownPassword,
            'password' => $this->newPassword,
            'password_confirmation' => $this->newPassword,
            'extra' => 'nope',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    });
});
