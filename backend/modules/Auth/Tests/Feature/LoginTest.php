<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Contracts\Repositories\AuthTokenRepository;
use Modules\Auth\Contracts\Services\AuthTokenIdGenerator;
use Modules\Auth\Contracts\Services\TokenHasher;
use Modules\Auth\Domain\Entities\AuthToken;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\Services\BearerTokenGenerator;
use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\LoginUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));

    $this->clientIp = '203.0.113.'.random_int(1, 254);
    $this->knownPassword = 'ValidPass1!xy';

    $keyFactory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($keyFactory->forLoginIp($this->clientIp));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function loginPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'login@example.com',
        'password' => 'ValidPass1!xy',
    ], $overrides);
}

function clearLoginRateLimits(string $ip, ?string $email = null): void
{
    $keyFactory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($keyFactory->forLoginIp($ip));

    if ($email !== null) {
        RateLimiter::clear($keyFactory->forLoginEmailIp($ip, $email));
    }
}

/**
 * @param  TestResponse<JsonResponse>  $response
 */
function assertInvalidCredentialsEnvelope(TestResponse $response): void
{
    $response->assertUnauthorized()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS')
        ->assertJsonPath('message', 'The provided credentials are invalid.');

    expect($response->json())->not->toHaveKey('data')
        ->and($response->json())->not->toHaveKey('token')
        ->and($response->json())->not->toHaveKey('user');
}

describe('POST /api/v1/auth/login', function () {
    it('returns 500 INTERNAL_ERROR when token issuance fails after valid credentials without persisting tokens', function () {
        UserModel::factory()
            ->active()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'issue-fail@example.com']);

        $this->app->instance(
            IssueAuthToken::class,
            new IssueAuthToken(
                authTokenRepository: new class implements AuthTokenRepository
                {
                    public function save(AuthToken $token, string $tokenHash): void
                    {
                        throw new RuntimeException('token issue failed');
                    }

                    public function findByHash(string $tokenHash): ?AuthToken
                    {
                        return null;
                    }

                    public function deleteById(AuthTokenId $id): void {}

                    public function deleteByHash(string $tokenHash): void {}

                    public function deleteAllForUser(UserId $userId): int
                    {
                        return 0;
                    }

                    public function touchLastUsedAtIfStale(AuthTokenId $id, DateTimeImmutable $now, int $minIntervalSeconds): bool
                    {
                        return false;
                    }
                },
                authTokenIdGenerator: $this->app->make(AuthTokenIdGenerator::class),
                bearerTokenGenerator: $this->app->make(BearerTokenGenerator::class),
                tokenHasher: $this->app->make(TokenHasher::class),
            ),
        );
        $this->app->forgetInstance(LoginUser::class);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'issue-fail@example.com',
                'password' => $this->knownPassword,
            ]));

        $response->assertStatus(500)
            ->assertJsonPath('code', 'INTERNAL_ERROR');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('logs in an active user with session token and required headers', function () {
        Carbon::setTestNow('2026-07-27T12:00:00+00:00');

        $user = UserModel::factory()
            ->active()
            ->withPassword($this->knownPassword)
            ->create([
                'email' => 'active@example.com',
                'name' => 'Active User',
            ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => '  Active@Example.com  ',
                'password' => $this->knownPassword,
            ]));

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.token_kind', 'session')
            ->assertJsonPath('data.expires_at', '2026-08-03T12:00:00Z')
            ->assertJsonPath('data.user.email', 'active@example.com')
            ->assertJsonPath('data.user.status', UserStatus::Active->value)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', 'Active User');

        $token = $response->json('data.token');

        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($token)->toBeString()
            ->and($token)->not->toBeEmpty()
            ->and($response->json())->not->toHaveKey('success')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(AuthTokenModel::query()->where('user_id', $user->id)->value('token_hash'))
            ->not->toBe($token);

        Carbon::setTestNow();
    });

    it('logs in a pending_verification user with verification token and does not queue email', function () {
        Carbon::setTestNow('2026-07-27T12:00:00+00:00');
        Queue::fake();

        $user = UserModel::factory()
            ->withPassword($this->knownPassword)
            ->create([
                'email' => 'pending@example.com',
                'status' => UserStatus::PendingVerification->value,
                'email_verified_at' => null,
            ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'pending@example.com',
                'password' => $this->knownPassword,
            ]));

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.token_kind', 'verification')
            ->assertJsonPath('data.expires_at', '2026-07-28T12:00:00Z')
            ->assertJsonPath('data.user.status', UserStatus::PendingVerification->value)
            ->assertJsonPath('data.user.email_verified_at', null);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and(UserModel::query()->where('id', $user->id)->value('status'))
            ->toBe(UserStatus::PendingVerification->value);

        Queue::assertNothingPushed();
        Queue::assertNotPushed(SendEmailVerificationJob::class);

        Carbon::setTestNow();
    });

    it('returns identical 401 INVALID_CREDENTIALS for unknown email, wrong password, and wrong password on suspended', function () {
        UserModel::factory()
            ->active()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'known@example.com']);

        UserModel::factory()
            ->suspended()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'blocked@example.com']);

        $unknown = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'missing@example.com',
                'password' => $this->knownPassword,
            ]));

        $wrongPassword = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'known@example.com',
                'password' => 'WrongPass1!xy',
            ]));

        $wrongOnSuspended = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'blocked@example.com',
                'password' => 'WrongPass1!xy',
            ]));

        assertInvalidCredentialsEnvelope($unknown);
        assertInvalidCredentialsEnvelope($wrongPassword);
        assertInvalidCredentialsEnvelope($wrongOnSuspended);

        expect([
            'code' => $unknown->json('code'),
            'message' => $unknown->json('message'),
            'status' => 401,
        ])->toBe([
            'code' => $wrongPassword->json('code'),
            'message' => $wrongPassword->json('message'),
            'status' => 401,
        ])->and([
            'code' => $wrongPassword->json('code'),
            'message' => $wrongPassword->json('message'),
            'status' => 401,
        ])->toBe([
            'code' => $wrongOnSuspended->json('code'),
            'message' => $wrongOnSuspended->json('message'),
            'status' => 401,
        ])
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 403 ACCOUNT_SUSPENDED for suspended account with correct password without issuing tokens', function () {
        UserModel::factory()
            ->suspended()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'suspended@example.com']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'suspended@example.com',
                'password' => $this->knownPassword,
            ]));

        $response->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED')
            ->assertJsonPath('message', 'The account is suspended.');

        expect($response->json())->not->toHaveKey('data')
            ->and($response->json('code'))->not->toBe('INVALID_CREDENTIALS')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 403 ACCOUNT_PENDING_DELETION for deletion_pending account with correct password without issuing tokens', function () {
        UserModel::factory()
            ->deletionPending()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'deleting@example.com']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'deleting@example.com',
                'password' => $this->knownPassword,
            ]));

        $response->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_PENDING_DELETION')
            ->assertJsonPath('message', 'The account is pending deletion.');

        expect($response->json())->not->toHaveKey('data')
            ->and($response->json('code'))->not->toBe('INVALID_CREDENTIALS')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 401 INVALID_CREDENTIALS for wrong password on deletion_pending account', function () {
        UserModel::factory()
            ->deletionPending()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'deleting-wrong@example.com']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'deleting-wrong@example.com',
                'password' => 'WrongPass1!xy',
            ]));

        assertInvalidCredentialsEnvelope($response);
        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('allows multiple successful logins without revoking preexisting tokens', function () {
        $user = UserModel::factory()
            ->active()
            ->withPassword($this->knownPassword)
            ->create(['email' => 'multi@example.com']);

        $first = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'multi@example.com',
                'password' => $this->knownPassword,
            ]));

        clearLoginRateLimits($this->clientIp, 'multi@example.com');

        $second = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'multi@example.com',
                'password' => $this->knownPassword,
            ]));

        $first->assertOk();
        $second->assertOk();

        expect($first->json('data.token'))->not->toBe($second->json('data.token'))
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->where('user_id', $user->id)->count())->toBe(2);
    });

    it('returns 422 VALIDATION_FAILED when payload contains extra fields', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'role' => 'admin',
            ]));

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['role']]);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED when email or password is missing', function () {
        $missingEmail = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', ['password' => $this->knownPassword]);

        $missingPassword = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', ['email' => 'login@example.com']);

        $emptyPassword = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'password' => '',
            ]));

        $missingEmail->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $missingPassword->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $emptyPassword->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 422 VALIDATION_FAILED for invalid or oversized email and oversized password', function () {
        $invalidEmail = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => 'not-an-email',
            ]));

        $email = 'user@'.implode('.', array_fill(0, 4, str_repeat('x', 61))).'.com';
        expect(strlen($email))->toBeGreaterThan(254);

        $oversizedEmail = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'email' => $email,
            ]));

        $oversizedPassword = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/login', loginPayload([
                'password' => str_repeat('a', 129),
            ]));

        $invalidEmail->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $oversizedEmail->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $oversizedPassword->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 400 MALFORMED_REQUEST for malformed JSON body', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->call(
                'POST',
                '/api/v1/auth/login',
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

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 400 MALFORMED_REQUEST when Content-Type is missing', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->call(
                'POST',
                '/api/v1/auth/login',
                [],
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => $this->clientIp,
                ],
                json_encode(loginPayload(), JSON_THROW_ON_ERROR),
            );

        $response->assertStatus(400)
            ->assertJsonPath('code', 'MALFORMED_REQUEST')
            ->assertJsonPath('message', 'The request is malformed.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('returns 400 MALFORMED_REQUEST when Content-Type is not JSON', function () {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->call(
                'POST',
                '/api/v1/auth/login',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'text/plain',
                    'HTTP_ACCEPT' => 'application/json',
                    'REMOTE_ADDR' => $this->clientIp,
                ],
                http_build_query(loginPayload()),
            );

        $response->assertStatus(400)
            ->assertJsonPath('code', 'MALFORMED_REQUEST')
            ->assertJsonPath('message', 'The request is malformed.');

        // @phpstan-ignore staticMethod.dynamicCall
        expect(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rate limits the sixth POST for the same email and IP with Retry-After', function () {
        $email = 'throttle-email@example.com';
        clearLoginRateLimits($this->clientIp, $email);

        $statuses = [];
        $sixth = null;

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
                ->postJson('/api/v1/auth/login', loginPayload([
                    'email' => $email,
                    'password' => '',
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
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });

    it('rate limits the 31st POST from the same IP across distinct emails with Retry-After', function () {
        clearLoginRateLimits($this->clientIp);

        $statuses = [];
        $thirtyFirst = null;

        for ($attempt = 1; $attempt <= 31; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
                ->postJson('/api/v1/auth/login', loginPayload([
                    'email' => "ip-limit-{$attempt}@example.com",
                    'password' => '',
                ]));

            $statuses[] = $response->status();

            if ($attempt === 31) {
                $thirtyFirst = $response;
            }
        }

        expect($statuses[29])->toBe(422)
            ->and($statuses[30])->toBe(429);
        expect($thirtyFirst)->not->toBeNull();

        assert($thirtyFirst instanceof TestResponse);

        $thirtyFirst->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
            ->assertJsonPath('message', 'Too many requests.');

        expect($thirtyFirst->headers->get('Retry-After'))->not->toBeNull()
            ->and((int) $thirtyFirst->headers->get('Retry-After'))->toBeGreaterThan(0)
            // @phpstan-ignore staticMethod.dynamicCall
            ->and(AuthTokenModel::query()->count())->toBe(0);
    });
});
