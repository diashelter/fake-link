<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Contracts\Services\InviteAllowlist;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\EmailAddress;
use Modules\Auth\Exceptions\InviteAllowlistUnavailableException;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\RegisterUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    $this->clientIp = '203.0.113.'.random_int(1, 254);
    $this->rateLimitKey = (new HmacRateLimitKeyFactory)->forRegistrationIp($this->clientIp);
    RateLimiter::clear($this->rateLimitKey);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Invited User',
        'email' => 'invited@example.com',
        'password' => 'ValidPass1!xy',
        'password_confirmation' => 'ValidPass1!xy',
        'accept_terms' => true,
    ], $overrides);
}

describe('POST /api/v1/auth/register', function () {
    it('registers an allowlisted user with verification token, terms, and queued email job', function () {
        Carbon::setTestNow('2026-07-26T12:00:00+00:00');
        Queue::fake();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => '  Invited@Example.com  ',
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.token_kind', 'verification')
            ->assertJsonPath('data.expires_at', '2026-07-27T12:00:00Z')
            ->assertJsonPath('data.user.email', 'invited@example.com')
            ->assertJsonPath('data.user.status', UserStatus::PendingVerification->value)
            ->assertJsonPath('data.user.terms_version', '2026-01')
            ->assertJsonPath('data.user.terms_accepted_at', '2026-07-26T12:00:00Z')
            ->assertJsonPath('data.user.email_verified_at', null);

        $token = $response->json('data.token');

        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($token)->toBeString()
            ->and($token)->not->toBeEmpty()
            ->and($response->json())->not->toHaveKey('success');

        $user = UserModel::query()->where('email', 'invited@example.com')->first();
        $tokenPlain = is_string($token) ? $token : '';

        expect($user)->not->toBeNull()
            ->and($user?->status)->toBe(UserStatus::PendingVerification->value)
            ->and($user?->terms_version)->toBe('2026-01')
            ->and($user?->email_verified_at)->toBeNull()
            ->and(Hash::check('ValidPass1!xy', (string) $user?->password))->toBeTrue()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $user?->id)->count())->toBe(1)
            ->and(AuthTokenModel::query()->where('user_id', $user?->id)->value('token_hash'))
            ->not->toBe($tokenPlain);

        Queue::assertPushed(SendEmailVerificationJob::class, 1);
        Queue::assertPushedOn('notifications', SendEmailVerificationJob::class);

        Carbon::setTestNow();
    });

    it('returns byte-equivalent 403 bodies for not invited and duplicate email', function () {
        $notInvited = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => 'stranger@example.com',
            ]));

        UserModel::factory()->create(['email' => 'invited@example.com']);

        $duplicate = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => 'invited@example.com',
            ]));

        $notInvited->assertForbidden();
        $duplicate->assertForbidden();

        expect($notInvited->json('code'))->toBe('REGISTRATION_NOT_ALLOWED')
            ->and($duplicate->json('code'))->toBe('REGISTRATION_NOT_ALLOWED')
            ->and($notInvited->json('message'))->toBe('Registration is not available for these details.')
            ->and($duplicate->json('message'))->toBe($notInvited->json('message'))
            ->and([
                'code' => $notInvited->json('code'),
                'message' => $notInvited->json('message'),
                'status' => 403,
            ])->toBe([
                'code' => $duplicate->json('code'),
                'message' => $duplicate->json('message'),
                'status' => 403,
            ])
            ->and($notInvited->json())->not->toHaveKey('data')
            ->and($duplicate->json())->not->toHaveKey('data')
            ->and(json_encode($notInvited->json()))->not->toContain('plain')
            ->and(json_encode($duplicate->json()))->not->toContain('token_hash');
    });

    it('returns 422 VALIDATION_FAILED for weak password without creating users', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'password' => 'short',
                'password_confirmation' => 'short',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 when accept_terms is false', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'accept_terms' => false,
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when password confirmation does not match', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'password' => 'ValidPass1!xy',
                'password_confirmation' => 'ValidPass1!zz',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when name is empty', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'name' => '',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when name exceeds 120 characters', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'name' => str_repeat('n', 121),
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when accept_terms is omitted', function () {
        $payload = registerPayload();
        unset($payload['accept_terms']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when accept_terms is a non-boolean string', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'accept_terms' => 'yes',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when email exceeds 254 characters', function () {
        // Valid-looking local@label.label...com shape that exceeds RFC max length 254.
        $email = 'user@'.implode('.', array_fill(0, 4, str_repeat('x', 61))).'.com';

        expect(strlen($email))->toBeGreaterThan(254);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => $email,
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 when payload contains extra fields', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'role' => 'admin',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['role']]);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0);
    });

    it('returns 422 for invalid email', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => 'not-an-email',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0);
    });

    it('returns 503 when invite allowlist is unavailable', function () {
        $this->app->instance(InviteAllowlist::class, new class implements InviteAllowlist
        {
            public function isInvited(EmailAddress $email): bool
            {
                throw InviteAllowlistUnavailableException::unavailable();
            }
        });
        $this->app->forgetInstance(RegisterUser::class);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload());

        $response->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('message', 'The service is temporarily unavailable.');

        expect(json_encode($response->json()))->not->toContain('invite')
            ->and(json_encode($response->json()))->not->toContain('allowlist');
    });

    it('rate limits the sixth POST from the same IP with Retry-After', function () {
        $statuses = [];
        $sixth = null;

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
                ->postJson('/api/v1/auth/register', registerPayload([
                    'email' => 'not-an-email',
                    'password' => 'short',
                    'password_confirmation' => 'short',
                ]));

            $statuses[] = $response->status();

            if ($attempt === 6) {
                $sixth = $response;
            }
        }

        expect($statuses)->toBe([422, 422, 422, 422, 422, 429]);
        expect($sixth)->not->toBeNull();

        assert($sixth instanceof TestResponse);

        $sixth->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
            ->assertJsonPath('message', 'Too many requests.');

        expect($sixth->headers->get('Retry-After'))->not->toBeNull()
            ->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThan(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(UserModel::query()->count())->toBe(0);
    });

    it('never includes plaintext tokens in error response bodies', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => 'stranger@example.com',
            ]));

        $response->assertForbidden();

        expect($response->json())->not->toHaveKey('token')
            ->and($response->json())->not->toHaveKey('data')
            ->and($response->json('code'))->toBe('REGISTRATION_NOT_ALLOWED');
    });

    it('returns 403 REGISTRATION_NOT_ALLOWED for unlisted plus-alias of allowlisted email', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'email' => 'invited+alias@example.com',
            ]));

        $response->assertForbidden()
            ->assertJsonPath('code', 'REGISTRATION_NOT_ALLOWED')
            ->assertJsonPath('message', 'Registration is not available for these details.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED for unicode password outside ASCII categories', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/register', registerPayload([
                'password' => 'café1234567!',
                'password_confirmation' => 'café1234567!',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 400 MALFORMED_REQUEST for malformed JSON body', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->call(
                'POST',
                '/api/v1/auth/register',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => $this->clientIp,
                ],
                '{not-json',
            );

        $response->assertStatus(400)
            ->assertJsonPath('code', 'MALFORMED_REQUEST')
            ->assertJsonPath('message', 'The request is malformed.');

        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(UserModel::query()->count())->toBe(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });
});
