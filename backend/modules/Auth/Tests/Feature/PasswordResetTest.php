<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Exceptions\InvalidPasswordResetTokenException;
use Modules\Auth\Exceptions\PasswordReusedException;
use Modules\Auth\Infrastructure\Jobs\SendPasswordResetJob;
use Modules\Auth\Infrastructure\Mail\PasswordResetMail;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    $this->clientIp = '203.0.113.'.random_int(1, 254);
    $this->knownPassword = 'ValidPass1!xy';
    $this->newPassword = 'BrandNewPass1!x';
});

function clearPasswordResetRequestLimit(string $ip, string $email): void
{
    $factory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($factory->forPasswordResetRequest($ip, $email));
}

function clearPasswordResetCompleteLimit(string $ip, string $token): void
{
    $factory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($factory->forPasswordResetComplete($ip, hash('sha256', $token)));
}

describe('POST /api/v1/auth/password/reset-request', function () {
    it('returns 202, issues a token, and queues mail for an active user', function () {
        Queue::fake();

        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.active@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.active@example.com');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.active@example.com',
            ]);

        $response->assertAccepted();
        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull();

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->where('purpose', 'password_reset')->count())->toBe(1);

        Queue::assertPushedOn('notifications', SendPasswordResetJob::class);
        Queue::assertPushed(SendPasswordResetJob::class, 1);
    });

    it('returns the same 202 without token or job for an unknown email', function () {
        Queue::fake();
        clearPasswordResetRequestLimit($this->clientIp, 'missing.reset@example.com');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'missing.reset@example.com',
            ]);

        $response->assertAccepted();
        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('returns 202 without token or job for a pending verification user', function () {
        Queue::fake();
        UserModel::factory()->withPassword($this->knownPassword)->create([
            'email' => 'pending.reset@example.com',
            'status' => UserStatus::PendingVerification->value,
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'pending.reset@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'pending.reset@example.com',
            ])
            ->assertAccepted();

        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('returns 429 on the fourth reset-request for the same email and IP', function () {
        UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'throttle.reset@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'throttle.reset@example.com');

        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
                ->postJson('/api/v1/auth/password/reset-request', [
                    'email' => 'throttle.reset@example.com',
                ])
                ->assertAccepted();
        }

        $limited = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'throttle.reset@example.com',
            ]);

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');
        expect($limited->headers->get('Retry-After'))->not->toBeNull();
    });

    it('returns 422 VALIDATION_FAILED for invalid email without side effects', function () {
        Queue::fake();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'not-an-email',
                'extra' => 'nope',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
        // @phpstan-ignore staticMethod.dynamicCall
        expect(EmailActionTokenModel::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });
});

describe('POST /api/v1/auth/password/reset', function () {
    it('resets password via request mail token and allows login with the new password', function () {
        Mail::fake();
        config([
            'auth.password_reset.frontend_base_url' => 'https://app.example.test',
            'auth.password_reset.frontend_path' => '/reset-password',
            'queue.default' => 'sync',
        ]);

        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.happy@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.happy@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.happy@example.com',
            ])
            ->assertAccepted();

        $plainToken = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$plainToken): bool {
            $query = parse_url($mail->resetUrl, PHP_URL_QUERY);
            parse_str((string) $query, $params);
            $plainToken = $params['token'] ?? null;

            return str_contains($mail->resetUrl, '/reset-password?token=')
                && $mail->envelope()->subject === 'Redefina sua senha — Fake Link';
        });

        expect($plainToken)->toBeString()
            ->and($plainToken)->not->toBe('');
        clearPasswordResetCompleteLimit($this->clientIp, (string) $plainToken);

        $loginBefore = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', [
                'email' => 'reset.happy@example.com',
                'password' => $this->knownPassword,
            ]);
        $loginBefore->assertOk();

        $reset = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.happy@example.com',
                'token' => $plainToken,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ]);

        $reset->assertNoContent();
        expect($reset->headers->get('Cache-Control'))->toContain('no-store')
            ->and($reset->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($reset->getContent())->toBe('');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and(UserModel::query()->find($user->id)?->status)->toBe(UserStatus::Active->value)
            ->and(EmailActionTokenModel::query()->where('user_id', $user->id)->where('purpose', 'password_reset')->value('used_at'))
            ->not->toBeNull();

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', [
                'email' => 'reset.happy@example.com',
                'password' => $this->newPassword,
            ])
            ->assertOk()
            ->assertJsonPath('data.token_kind', 'session');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', [
                'email' => 'reset.happy@example.com',
                'password' => $this->knownPassword,
            ])
            ->assertUnauthorized();
    });

    it('returns 422 on the token field for an invalid reset token', function () {
        UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.invalid@example.com',
        ]);
        clearPasswordResetCompleteLimit($this->clientIp, 'not-valid');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.invalid@example.com',
                'token' => 'not-valid',
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.token.0.message', InvalidPasswordResetTokenException::MESSAGE)
            ->assertJsonPath('errors.token.0.code', 'INVALID');
    });

    it('returns 422 PASSWORD_REUSED without consuming the token', function () {
        Queue::fake();
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.reused@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.reused@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.reused@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        $plainToken = Crypt::decryptString($job->encryptedToken);
        clearPasswordResetCompleteLimit($this->clientIp, $plainToken);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.reused@example.com',
                'token' => $plainToken,
                'password' => $this->knownPassword,
                'password_confirmation' => $this->knownPassword,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.password.0.code', PasswordReusedException::PASSWORD_REUSED)
            ->assertJsonPath('errors.password.0.message', PasswordReusedException::MESSAGE);

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))->toBeNull();
    });

    it('returns 422 for a weak password without consuming the token', function () {
        Queue::fake();
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.weak@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.weak@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.weak@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $plainToken = Crypt::decryptString($job->encryptedToken);
        clearPasswordResetCompleteLimit($this->clientIp, $plainToken);

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.weak@example.com',
                'token' => $plainToken,
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))->toBeNull()
            ->and(UserModel::query()->find($user->id)?->password)->toBe($user->password);
    });

    it('returns 422 when password confirmation does not match without consuming the token', function () {
        Queue::fake();
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.mismatch@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.mismatch@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.mismatch@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $plainToken = Crypt::decryptString($job->encryptedToken);
        clearPasswordResetCompleteLimit($this->clientIp, $plainToken);

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.mismatch@example.com',
                'token' => $plainToken,
                'password' => $this->newPassword,
                'password_confirmation' => 'DifferentPass1!x',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))->toBeNull()
            ->and(UserModel::query()->find($user->id)?->password)->toBe($user->password);
    });

    it('rejects a used reset token as invalid even when the password matches the current hash', function () {
        Queue::fake();
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.used.same@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.used.same@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.used.same@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $plainToken = Crypt::decryptString($job->encryptedToken);
        clearPasswordResetCompleteLimit($this->clientIp, $plainToken);

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.used.same@example.com',
                'token' => $plainToken,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ])
            ->assertNoContent();

        clearPasswordResetCompleteLimit($this->clientIp, $plainToken);

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.used.same@example.com',
                'token' => $plainToken,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0.message', InvalidPasswordResetTokenException::MESSAGE)
            ->assertJsonPath('errors.token.0.code', 'INVALID');
    });

    it('returns 422 on the token field when the plaintext token has trailing whitespace', function () {
        Queue::fake();
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.whitespace@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.whitespace@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.whitespace@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $plainToken = Crypt::decryptString($job->encryptedToken);
        $tokenWithWhitespace = $plainToken.' ';
        clearPasswordResetCompleteLimit($this->clientIp, $tokenWithWhitespace);

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'reset.whitespace@example.com',
                'token' => $tokenWithWhitespace,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.token.0.message', InvalidPasswordResetTokenException::MESSAGE)
            ->assertJsonPath('errors.token.0.code', 'INVALID');

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))->toBeNull();
    });

    it('does not mutate state when reset is attempted via GET', function () {
        Queue::fake();
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'reset.get@example.com',
        ]);
        clearPasswordResetRequestLimit($this->clientIp, 'reset.get@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'reset.get@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        $plainToken = Crypt::decryptString($job->encryptedToken);

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->getJson('/api/v1/auth/password/reset?token='.$plainToken)
            ->assertStatus(405);

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))->toBeNull()
            ->and(UserModel::query()->find($user->id)?->password)->toBe($user->password);
    });
});
