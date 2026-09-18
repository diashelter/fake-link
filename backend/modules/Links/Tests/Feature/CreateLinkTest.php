<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Exceptions\AuthTokenException;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\RateLimit\LinkRateLimitKeyFactory;
use Modules\Links\UseCases\CreateLink;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    Carbon::setTestNow('2026-06-15T12:00:00+00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function createLinkSessionBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function createLinkVerificationBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function activeLinkOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function postCreateLink(array $payload = [], array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

/**
 * @return array{reservations: int, links: int, versions: int}
 */
function createLinkTableCounts(): array
{
    return [
        'reservations' => DB::table('slug_reservations')->count(),
        'links' => DB::table('short_links')->count(),
        'versions' => DB::table('link_destination_versions')->count(),
    ];
}

final class FeatureCreateLinkFixedSlugSource implements RandomSlugSource
{
    public function __construct(private readonly string $candidate) {}

    public function candidate(int $length, string $alphabet): string
    {
        return $this->candidate;
    }
}

describe('POST /api/v1/links happy path', function () {
    it('creates a link with session bearer and returns 201 with Location, ETag and LinkDetail', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            ['destination_url' => 'https://example.com/path'],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated()
            ->assertJsonPath('data.destination_url', 'https://example.com/path')
            ->assertJsonPath('data.slug_source', 'automatic')
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.title', null);

        $id = $response->json('data.id');
        $slug = $response->json('data.slug');

        expect($response->headers->get('Location'))->toBe('/api/v1/links/'.$id)
            ->and($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{64}"$/')
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($response->json('data.short_url'))->toBe('https://go.localhost/'.$slug)
            ->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($response->json('data.updated_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 1,
            'links' => 1,
            'versions' => 1,
        ]);
    });

    it('builds short_url from configured base, not the request host', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            ['destination_url' => 'https://example.com/x'],
            [
                'Authorization' => 'Bearer '.$bearer,
                'HTTP_HOST' => 'app.evil.test',
            ],
        );

        $response->assertCreated();
        expect($response->json('data.short_url'))->toStartWith('https://go.localhost/')
            ->and($response->json('data.short_url'))->not->toContain('evil');
    });

    it('normalizes mixed-case custom alias and sets slug_source custom', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            [
                'destination_url' => 'https://example.com/custom',
                'custom_alias' => 'My-Cool-Alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'my-cool-alias')
            ->assertJsonPath('data.slug_source', 'custom')
            ->assertJsonPath('data.short_url', 'https://go.localhost/my-cool-alias');
    });

    it('persists title and future expires_at', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            [
                'destination_url' => 'https://example.com/titled',
                'title' => '  Campanha  ',
                'expires_at' => '2026-12-31T23:59:59Z',
                'custom_alias' => 'titled-link',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Campanha')
            ->assertJsonPath('data.expires_at', '2026-12-31T23:59:59Z');
    });

    it('sets slug_source automatic when custom_alias is omitted', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            ['destination_url' => 'https://example.com/auto'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated()
            ->assertJsonPath('data.slug_source', 'automatic');
    });
});

describe('POST /api/v1/links conflicts', function () {
    it('returns 409 ALIAS_UNAVAILABLE for an alias backed by a short link', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/first',
                'custom_alias' => 'taken-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $response = postCreateLink(
            [
                'destination_url' => 'https://example.com/second',
                'custom_alias' => 'Taken-Alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertStatus(409)
            ->assertJsonPath('code', 'ALIAS_UNAVAILABLE')
            ->assertJsonMissingPath('data');

        $body = json_encode($response->json(), JSON_THROW_ON_ERROR);
        expect(strtolower($body))->not->toContain('taken-alias')
            ->and($body)->not->toContain($user->id)
            ->and($body)->not->toContain('example.com');
    });

    it('returns identical 409 for an orphan reservation', function () {
        SlugReservationModel::query()->create([
            'slug' => 'orphan-alias',
            'reserved_at' => now(),
        ]);

        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $linked = postCreateLink(
            [
                'destination_url' => 'https://example.com/linked',
                'custom_alias' => 'linked-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $linked->assertCreated();

        $linkedConflict = postCreateLink(
            [
                'destination_url' => 'https://example.com/again',
                'custom_alias' => 'linked-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $orphanConflict = postCreateLink(
            [
                'destination_url' => 'https://example.com/orphan',
                'custom_alias' => 'orphan-alias',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $linkedConflict->assertStatus(409);
        $orphanConflict->assertStatus(409);

        expect($orphanConflict->json('code'))->toBe($linkedConflict->json('code'))
            ->and($orphanConflict->json('message'))->toBe($linkedConflict->json('message'))
            ->and(array_keys($orphanConflict->json()))->toBe(array_keys($linkedConflict->json()));
    });

    it('prefers 422 INVALID_ALIAS over 409 when alias is invalid even if occupied', function () {
        SlugReservationModel::query()->create([
            'slug' => 'ab',
            'reserved_at' => now(),
        ]);

        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);
        $before = createLinkTableCounts();

        $response = postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'custom_alias' => 'ab',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.custom_alias.0.code', 'INVALID_ALIAS');

        expect(createLinkTableCounts())->toBe($before);
    });
});

describe('POST /api/v1/links slug generation exhaustion', function () {
    it('returns 503 SLUG_GENERATION_FAILED with Retry-After when generation is exhausted', function () {
        config(['links.slug.max_collision_attempts' => 2]);

        // Re-bind CreateLink so the lowered collision ceiling is applied.
        app()->forgetInstance(CreateLink::class);
        app()->bind(RandomSlugSource::class, fn () => new FeatureCreateLinkFixedSlugSource('stuck123'));

        SlugReservationModel::query()->create([
            'slug' => 'stuck123',
            'reserved_at' => now(),
        ]);

        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            ['destination_url' => 'https://example.com/exhaust'],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertStatus(503)
            ->assertJsonPath('code', SlugGenerationExhausted::ERROR_CODE);

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
        // @phpstan-ignore staticMethod.dynamicCall
        expect(ShortLinkModel::query()->count())->toBe(0);
    });
});

describe('POST /api/v1/links authentication and authorization', function () {
    it('returns 401 UNAUTHENTICATED without a bearer', function () {
        postCreateLink(['destination_url' => 'https://example.com/x'])
            ->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 401 UNAUTHENTICATED for an invalid bearer', function () {
        postCreateLink(
            ['destination_url' => 'https://example.com/x'],
            ['Authorization' => 'Bearer not-a-real-token'],
        )->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 403 TOKEN_RESTRICTED for a verification bearer', function () {
        $user = activeLinkOwner();
        $bearer = createLinkVerificationBearer($user);

        postCreateLink(
            ['destination_url' => 'https://example.com/x'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);

        expect(createLinkTableCounts()['links'])->toBe(0);
    });

    it('returns 403 ACCOUNT_SUSPENDED for a suspended account', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::Suspended->value]);
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            ['destination_url' => 'https://example.com/x'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_SUSPENDED);
    });

    it('returns 403 ACCOUNT_PENDING_DELETION for a deletion-pending account', function () {
        $user = UserModel::factory()->create(['status' => UserStatus::DeletionPending->value]);
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            ['destination_url' => 'https://example.com/x'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_PENDING_DELETION);
    });
});

describe('POST /api/v1/links validation leaves no rows', function () {
    it('returns 422 REQUIRED for missing destination_url without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink([], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422)
            ->assertJsonPath('errors.destination_url.0.code', 'REQUIRED');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 INVALID_DESTINATION_URL without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            ['destination_url' => 'javascript:alert(1)'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.destination_url.0.code', 'INVALID_DESTINATION_URL');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 INVALID_ALIAS without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'custom_alias' => '!!',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.custom_alias.0.code', 'INVALID_ALIAS');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 for reserved-word alias without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'custom_alias' => 'ADMIN',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.custom_alias.0.code', 'INVALID_ALIAS');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 TITLE_TOO_LONG without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'title' => str_repeat('a', 161),
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.title.0.code', 'TITLE_TOO_LONG');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 EXPIRES_AT_NOT_IN_FUTURE without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'expires_at' => '2020-01-01T00:00:00Z',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.expires_at.0.code', 'EXPIRES_AT_NOT_IN_FUTURE');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 INVALID_DATETIME without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'expires_at' => '2026-12-31T23:59:59+00:00',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.expires_at.0.code', 'INVALID_DATETIME');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('returns 422 UNKNOWN_FIELD without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            [
                'destination_url' => 'https://example.com/ok',
                'extra' => 'nope',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.extra.0.code', 'UNKNOWN_FIELD');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('does not echo the destination URL in validation errors', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            ['destination_url' => 'ftp://secret-never-echo.example/leak-me'],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertStatus(422);
        $body = json_encode($response->json(), JSON_THROW_ON_ERROR);
        expect($body)->not->toContain('secret-never-echo')
            ->and($body)->not->toContain('leak-me')
            ->and($body)->not->toContain('ftp://');
    });
});

describe('POST /api/v1/links ownership and route registration', function () {
    it('associates the created link with the authenticated user', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            [
                'destination_url' => 'https://example.com/owner',
                'custom_alias' => 'owner-check',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated();

        $link = ShortLinkModel::query()->where('slug', 'owner-check')->firstOrFail();
        expect($link->user_id)->toBe($user->id);
    });

    it('rejects self-host destinations with 422 and no rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            ['destination_url' => 'https://go.localhost/loop'],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertStatus(422)
            ->assertJsonPath('errors.destination_url.0.code', 'INVALID_DESTINATION_URL');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ]);
    });

    it('exposes only the LinkDetail fields under data', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        $response = postCreateLink(
            ['destination_url' => 'https://example.com/fields'],
            ['Authorization' => 'Bearer '.$bearer],
        );

        $response->assertCreated();
        expect(array_keys($response->json('data')))->toEqualCanonicalizing([
            'id',
            'slug',
            'short_url',
            'destination_url',
            'title',
            'slug_source',
            'is_enabled',
            'status',
            'expires_at',
            'created_at',
            'updated_at',
        ]);
    });

    it('registers POST api/v1/links behind auth.bearer and token.kind session', function () {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => in_array('POST', $route->methods(), true)
                && $route->uri() === 'api/v1/links');

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('auth.bearer')
            ->and($route->gatherMiddleware())->toContain('token.kind:session')
            ->and($route->gatherMiddleware())->toContain('throttle.links.create');
    });
});

describe('POST /api/v1/links idempotency', function () {
    it('returns 401 UNAUTHENTICATED with Idempotency-Key and without a bearer', function () {
        postCreateLink(
            ['destination_url' => 'https://example.com/idem-401'],
            ['Idempotency-Key' => 'idem-key-401-abcdefgh'],
        )->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);

        expect(createLinkTableCounts()['links'])->toBe(0)
            ->and(DB::table('idempotency_keys')->count())->toBe(0);
    });

    it('returns 403 TOKEN_RESTRICTED with Idempotency-Key for a verification bearer', function () {
        $user = activeLinkOwner();
        $bearer = createLinkVerificationBearer($user);

        postCreateLink(
            ['destination_url' => 'https://example.com/idem-403'],
            [
                'Authorization' => 'Bearer '.$bearer,
                'Idempotency-Key' => 'idem-key-403-abcdefgh',
            ],
        )->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);

        expect(createLinkTableCounts()['links'])->toBe(0)
            ->and(DB::table('idempotency_keys')->count())->toBe(0);
    });

    it('returns 422 INVALID_IDEMPOTENCY_KEY without writing rows', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);

        postCreateLink(
            ['destination_url' => 'https://example.com/idem-422'],
            [
                'Authorization' => 'Bearer '.$bearer,
                'Idempotency-Key' => 'too-short',
            ],
        )->assertStatus(422)
            ->assertJsonPath('errors.Idempotency-Key.0.code', 'INVALID_IDEMPOTENCY_KEY');

        expect(createLinkTableCounts())->toBe([
            'reservations' => 0,
            'links' => 0,
            'versions' => 0,
        ])->and(DB::table('idempotency_keys')->count())->toBe(0);
    });

    it('returns 429 RATE_LIMIT_EXCEEDED with Idempotency-Key when the create budget is exhausted', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);
        $userId = UserId::fromString($user->id);
        $key = (new LinkRateLimitKeyFactory)->forLinkCreation($userId);
        $maxAttempts = (int) config('links.rate_limits.create.max_attempts', 60);
        $decaySeconds = (int) config('links.rate_limits.create.decay_seconds', 60);

        RateLimiter::clear($key);
        for ($i = 0; $i < $maxAttempts; $i++) {
            RateLimiter::hit($key, $decaySeconds);
        }

        postCreateLink(
            ['destination_url' => 'https://example.com/idem-429'],
            [
                'Authorization' => 'Bearer '.$bearer,
                'Idempotency-Key' => 'idem-key-429-abcdefgh',
            ],
        )->assertStatus(429)
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED');

        expect(createLinkTableCounts()['links'])->toBe(0)
            ->and(DB::table('idempotency_keys')->count())->toBe(0);

        RateLimiter::clear($key);
    });

    it('creates once and replays the exact 201 body and semantic headers', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);
        $headers = [
            'Authorization' => 'Bearer '.$bearer,
            'Idempotency-Key' => 'idem-key-replay-abcdef',
        ];
        $payload = ['destination_url' => 'https://example.com/idem-replay'];

        $first = postCreateLink($payload, $headers);
        $first->assertCreated();

        $second = postCreateLink($payload, $headers);
        $second->assertCreated();

        // @phpstan-ignore staticMethod.dynamicCall
        $firstBody = $first->getContent();
        // @phpstan-ignore staticMethod.dynamicCall
        $secondBody = $second->getContent();

        expect($secondBody)->toBe($firstBody)
            ->and($second->headers->get('Location'))->toBe($first->headers->get('Location'))
            ->and($second->headers->get('ETag'))->toBe($first->headers->get('ETag'))
            ->and($second->headers->get('Cache-Control'))->toBe($first->headers->get('Cache-Control'))
            ->and(createLinkTableCounts()['links'])->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1);
    });

    it('returns 409 IDEMPOTENCY_KEY_REUSED for the same key with a different command', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);
        $headers = [
            'Authorization' => 'Bearer '.$bearer,
            'Idempotency-Key' => 'idem-key-conflict-abcdef',
        ];

        postCreateLink(
            ['destination_url' => 'https://example.com/idem-a'],
            $headers,
        )->assertCreated();

        $conflict = postCreateLink(
            ['destination_url' => 'https://example.com/idem-b'],
            $headers,
        );

        $conflict->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        // @phpstan-ignore staticMethod.dynamicCall
        $json = $conflict->getContent();
        expect($json)->not->toContain('https://example.com/idem-a')
            ->and($json)->not->toContain('https://example.com/idem-b')
            ->and($json)->not->toContain('idem-key-conflict')
            ->and(createLinkTableCounts()['links'])->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1);
    });

    it('returns 503 SERVICE_UNAVAILABLE when a stored snapshot cannot be decrypted', function () {
        $user = activeLinkOwner();
        $bearer = createLinkSessionBearer($user);
        $headers = [
            'Authorization' => 'Bearer '.$bearer,
            'Idempotency-Key' => 'idem-key-decrypt-abcdef',
        ];
        $payload = ['destination_url' => 'https://example.com/idem-decrypt'];

        postCreateLink($payload, $headers)->assertCreated();

        DB::update(
            "UPDATE idempotency_keys SET response_snapshot = decode(?, 'hex')",
            [bin2hex(random_bytes(64))],
        );

        $failed = postCreateLink($payload, $headers);

        $failed->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE');

        // @phpstan-ignore staticMethod.dynamicCall
        $failedBody = $failed->getContent();

        expect($failed->headers->get('Location'))->toBeNull()
            ->and($failed->headers->get('ETag'))->toBeNull()
            ->and($failedBody)->not->toContain('https://example.com/idem-decrypt')
            ->and(createLinkTableCounts()['links'])->toBe(1);
    });
});
