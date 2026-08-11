<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Modules\Auth\UseCases\IssuePasswordResetToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    $this->knownPassword = 'ValidPass1!xy';
});

function issueVerificationBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function issueEmailVerificationPlaintext(UserModel $user): string
{
    return app(IssueEmailVerificationToken::class)
        ->execute(UserId::fromString($user->id))
        ->plainTextToken;
}

function clearEmailVerificationRateLimits(UserId $userId): void
{
    $factory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($factory->forEmailVerificationResend($userId));
    RateLimiter::clear($factory->forEmailVerificationVerify($userId));
}

describe('POST /api/v1/auth/email/verify', function () {
    it('verifies a pending user, returns 204, activates the account, and revokes the bearer', function () {
        Carbon::setTestNow('2026-07-27T18:00:00+00:00');

        $user = UserModel::factory()->withPassword($this->knownPassword)->create([
            'email' => 'verify-happy@example.com',
        ]);
        $bearer = issueVerificationBearer($user);
        $emailToken = issueEmailVerificationPlaintext($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verify', [
            'token' => $emailToken,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertNoContent();
        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($response->getContent())->toBe('');

        $fresh = UserModel::query()->find($user->id);
        $emailTokens = EmailActionTokenModel::query()->where('user_id', $user->id)->get();
        // @phpstan-ignore staticMethod.dynamicCall
        $remainingBearers = AuthTokenModel::query()->where('user_id', $user->id)->count();

        expect($fresh?->status)->toBe(UserStatus::Active->value)
            ->and($fresh?->email_verified_at->toIso8601String())->toBe('2026-07-27T18:00:00+00:00')
            ->and($emailTokens->filter(fn ($token): bool => $token->used_at !== null)->count())->toBe(1)
            ->and($remainingBearers)->toBe(0);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'verify-happy@example.com',
            'password' => $this->knownPassword,
        ]);

        $login->assertOk()
            ->assertJsonPath('data.token_kind', 'session')
            ->assertJsonPath('data.user.status', UserStatus::Active->value);

        Carbon::setTestNow();
    });

    it('returns 403 INVALID_VERIFICATION_TOKEN for an invalid email token', function () {
        $user = UserModel::factory()->create();
        $bearer = issueVerificationBearer($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verify', [
            'token' => 'not-valid',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('code', 'INVALID_VERIFICATION_TOKEN')
            ->assertJsonPath('message', 'The verification token is invalid or has expired.');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('returns 403 INVALID_VERIFICATION_TOKEN when a password_reset token is submitted', function () {
        $user = UserModel::factory()->create();
        $bearer = issueVerificationBearer($user);
        $passwordResetToken = app(IssuePasswordResetToken::class)
            ->execute(UserId::fromString($user->id))
            ->plainTextToken;
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/email/verify', [
            'token' => $passwordResetToken,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'INVALID_VERIFICATION_TOKEN')
            ->assertJsonPath('message', 'The verification token is invalid or has expired.');

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->where('purpose', 'password_reset')->value('used_at'))
            ->toBeNull();
    });

    it('returns 403 EMAIL_ALREADY_VERIFIED when the user is already active', function () {
        $user = UserModel::factory()->active()->create();
        $bearer = issueVerificationBearer($user);
        $emailToken = issueEmailVerificationPlaintext($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verify', [
            'token' => $emailToken,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_ALREADY_VERIFIED')
            ->assertJsonPath('message', 'The email address is already verified.');
    });

    it('returns 422 VALIDATION_FAILED for whitespace-only token without consuming email action tokens', function () {
        $user = UserModel::factory()->create([
            'email' => 'verify-whitespace@example.com',
        ]);
        $bearer = issueVerificationBearer($user);
        $emailToken = issueEmailVerificationPlaintext($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verify', [
            'token' => ' ',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        expect(EmailActionTokenModel::query()->where('user_id', $user->id)->value('used_at'))
            ->toBeNull()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(EmailActionTokenModel::query()->where('user_id', $user->id)->count())->toBe(1);

        $this->getJson('/api/v1/_test/auth/probe', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();

        // Ensure a real token still works after the whitespace rejection.
        clearEmailVerificationRateLimits(UserId::fromString($user->id));
        $this->postJson('/api/v1/auth/email/verify', [
            'token' => $emailToken,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertNoContent();
    });

    it('returns 422 VALIDATION_FAILED when token is missing or extra fields are present', function () {
        $user = UserModel::factory()->create();
        $bearer = issueVerificationBearer($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $missing = $this->postJson('/api/v1/auth/email/verify', [], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $missing->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $extra = $this->postJson('/api/v1/auth/email/verify', [
            'token' => 'abc',
            'extra' => true,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $extra->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    });

    it('returns 401 when bearer is missing and 403 TOKEN_RESTRICTED for session tokens', function () {
        $user = UserModel::factory()->create();
        $emailToken = issueEmailVerificationPlaintext($user);
        $session = app(IssueAuthToken::class)->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
        )->plainTextToken;

        $this->postJson('/api/v1/auth/email/verify', [
            'token' => $emailToken,
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/auth/email/verify', [
            'token' => $emailToken,
        ], [
            'Authorization' => 'Bearer '.$session,
        ])->assertForbidden()
            ->assertJsonPath('code', 'TOKEN_RESTRICTED');
    });

    it('returns 429 RATE_LIMIT_EXCEEDED on the sixth verify attempt', function () {
        $user = UserModel::factory()->create();
        $bearer = issueVerificationBearer($user);
        $userId = UserId::fromString($user->id);
        clearEmailVerificationRateLimits($userId);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/email/verify', [
                'token' => 'bad-token-'.$attempt,
            ], [
                'Authorization' => 'Bearer '.$bearer,
            ])->assertForbidden();
        }

        $limited = $this->postJson('/api/v1/auth/email/verify', [
            'token' => 'bad-token-6',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect($limited->headers->get('Retry-After'))->not->toBeNull();
    });

    it('does not verify via GET', function () {
        $user = UserModel::factory()->create();
        $bearer = issueVerificationBearer($user);
        $emailToken = issueEmailVerificationPlaintext($user);

        $this->getJson('/api/v1/auth/email/verify?token='.urlencode($emailToken), [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertStatus(405);

        expect(UserModel::query()->find($user->id)?->status)
            ->toBe(UserStatus::PendingVerification->value);
    });
});

describe('POST /api/v1/auth/email/verification-notification', function () {
    it('resends verification with 202 and queues a mail job', function () {
        Queue::fake();
        Mail::fake();

        $user = UserModel::factory()->create(['email' => 'resend@example.com']);
        $bearer = issueVerificationBearer($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertAccepted();
        $emailTokens = EmailActionTokenModel::query()->where('user_id', $user->id)->get();

        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($emailTokens->filter(fn ($token): bool => $token->used_at === null)->count())->toBe(1);

        Queue::assertPushed(SendEmailVerificationJob::class, 1);
        Queue::assertPushedOn('notifications', SendEmailVerificationJob::class);
    });

    it('returns 403 EMAIL_ALREADY_VERIFIED for active users', function () {
        Queue::fake();

        $user = UserModel::factory()->active()->create();
        $bearer = issueVerificationBearer($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertForbidden()
            ->assertJsonPath('code', 'EMAIL_ALREADY_VERIFIED');

        Queue::assertNothingPushed();
    });

    it('returns 401 when bearer is missing and 403 TOKEN_RESTRICTED for session tokens', function () {
        Queue::fake();

        $user = UserModel::factory()->create();
        $session = app(IssueAuthToken::class)->execute(
            new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
        )->plainTextToken;

        $this->postJson('/api/v1/auth/email/verification-notification', [])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$session,
        ])->assertForbidden()
            ->assertJsonPath('code', 'TOKEN_RESTRICTED');

        Queue::assertNothingPushed();
    });

    it('returns 403 ACCOUNT_SUSPENDED and ACCOUNT_PENDING_DELETION for blocked accounts', function () {
        Queue::fake();

        $suspended = UserModel::factory()->suspended()->create();
        $suspendedBearer = issueVerificationBearer($suspended);
        clearEmailVerificationRateLimits(UserId::fromString($suspended->id));

        $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$suspendedBearer,
        ])->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');

        $pendingDeletion = UserModel::factory()->deletionPending()->create();
        $pendingBearer = issueVerificationBearer($pendingDeletion);
        clearEmailVerificationRateLimits(UserId::fromString($pendingDeletion->id));

        $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$pendingBearer,
        ])->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_PENDING_DELETION');

        Queue::assertNothingPushed();
    });

    it('returns 429 on the fourth resend attempt', function () {
        Queue::fake();

        $user = UserModel::factory()->create();
        $bearer = issueVerificationBearer($user);
        clearEmailVerificationRateLimits(UserId::fromString($user->id));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/email/verification-notification', [], [
                'Authorization' => 'Bearer '.$bearer,
            ])->assertAccepted();
        }

        $limited = $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $limited->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect($limited->headers->get('Retry-After'))->not->toBeNull();
    });
});
