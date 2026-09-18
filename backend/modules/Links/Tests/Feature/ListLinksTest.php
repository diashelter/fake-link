<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkPageRecords;
use Modules\Links\DTOs\Output\PersistedLinkDetail;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\UseCases\ListLinks;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    Carbon::setTestNow('2026-06-15T12:00:00+00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

final class ListLinksQueryRepositorySpy implements LinkQueryRepository
{
    public int $listCalls = 0;

    public function __construct(private readonly LinkQueryRepository $inner) {}

    public function listForOwner(
        UserId $ownerId,
        ListLinksQuery $query,
        ?CursorAnchor $anchor,
        DateTimeImmutable $now,
    ): LinkPageRecords {
        $this->listCalls++;

        return $this->inner->listForOwner($ownerId, $query, $anchor, $now);
    }

    public function findForOwner(UserId $ownerId, ShortLinkId $linkId): ?PersistedLinkDetail
    {
        return $this->inner->findForOwner($ownerId, $linkId);
    }
}

function listHttpOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function listHttpSessionBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function listHttpVerificationBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function listLinksSeed(array $payload, array $headers): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    return $response;
}

/**
 * @param  array<string, scalar>  $query
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getListLinks(array $query = [], array $headers = []): TestResponse
{
    $uri = '/api/v1/links';

    if ($query !== []) {
        $uri .= '?'.http_build_query($query);
    }

    // @phpstan-ignore method.notFound
    $response = test()->getJson($uri, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

function bindListLinksQuerySpy(): ListLinksQueryRepositorySpy
{
    $spy = new ListLinksQueryRepositorySpy(app(LinkQueryRepository::class));
    app()->instance(LinkQueryRepository::class, $spy);
    app()->forgetInstance(ListLinks::class);

    return $spy;
}

/**
 * @param  array<string, mixed>  $payload
 */
function listLinksSignCursor(array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $key = (string) config('links.cursor_hmac_key');
    $body = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $json, $key, true)), '+/', '-_'), '=');

    return $body.'.'.$mac;
}

/**
 * @return list<string>
 */
function listLinksSummaryKeys(): array
{
    return [
        'id',
        'slug',
        'short_url',
        'title',
        'slug_source',
        'is_enabled',
        'status',
        'expires_at',
        'created_at',
        'updated_at',
    ];
}

describe('GET /api/v1/links happy path', function () {
    it('returns 200 with up to 20 owner summaries in created_at DESC then id DESC', function () {
        $owner = listHttpOwner();
        $foreign = listHttpOwner();
        $ownerBearer = listHttpSessionBearer($owner);
        $foreignBearer = listHttpSessionBearer($foreign);

        $created = [];

        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow((new DateTimeImmutable('2026-06-15T12:00:00Z'))->modify("+{$i} seconds"));
            $response = listLinksSeed(
                [
                    'destination_url' => 'https://example.com/owner-'.$i,
                    'custom_alias' => 'owner-ord-'.$i,
                    'title' => 'Owner '.$i,
                ],
                ['Authorization' => 'Bearer '.$ownerBearer],
            );
            $response->assertCreated();
            $created[] = $response->json('data.id');
        }

        Carbon::setTestNow('2026-06-15T12:00:10+00:00');
        listLinksSeed(
            [
                'destination_url' => 'https://example.com/foreign',
                'custom_alias' => 'foreign-ord',
            ],
            ['Authorization' => 'Bearer '.$foreignBearer],
        )->assertCreated();

        Carbon::setTestNow('2026-06-15T12:00:20+00:00');
        $response = getListLinks([], ['Authorization' => 'Bearer '.$ownerBearer]);

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(3)
            ->and($response->json('data.0.id'))->toBe($created[2])
            ->and($response->json('data.1.id'))->toBe($created[1])
            ->and($response->json('data.2.id'))->toBe($created[0])
            ->and($response->json('meta'))->toBe([
                'next_cursor' => null,
                'per_page' => 20,
            ])
            ->and($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeNull()
            ->and($response->headers->get('ETag'))->toBeNull();
    });

    it('returns empty data, null next_cursor and effective per_page when the portfolio is empty', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        $response = getListLinks([], ['Authorization' => 'Bearer '.$bearer]);

        $response->assertOk();
        expect($response->json('data'))->toBe([])
            ->and($response->json('meta.next_cursor'))->toBeNull()
            ->and($response->json('meta.per_page'))->toBe(20)
            ->and(array_keys($response->json('meta')))->toEqualCanonicalizing(['next_cursor', 'per_page']);
    });

    it('emits a non-null next_cursor only when more results exist and follows without repeats', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);
        $ids = [];

        for ($i = 0; $i < 21; $i++) {
            Carbon::setTestNow((new DateTimeImmutable('2026-06-15T12:00:00Z'))->modify("+{$i} seconds"));
            $alias = 'pageitem'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $created = listLinksSeed(
                [
                    'destination_url' => 'https://example.com/'.$alias,
                    'custom_alias' => $alias,
                ],
                ['Authorization' => 'Bearer '.$bearer],
            );
            $created->assertCreated();
            $ids[] = $created->json('data.id');
        }

        Carbon::setTestNow('2026-06-15T12:01:00+00:00');
        $first = getListLinks([], ['Authorization' => 'Bearer '.$bearer]);
        $first->assertOk();

        $firstIds = $first->json('data.*.id');
        $cursor = $first->json('meta.next_cursor');

        expect($first->json('data'))->toHaveCount(20)
            ->and($cursor)->toBeString()
            ->and($cursor)->not->toBe('')
            ->and($first->json('meta.per_page'))->toBe(20);

        $second = getListLinks(['cursor' => $cursor], ['Authorization' => 'Bearer '.$bearer]);
        $second->assertOk();

        $secondIds = $second->json('data.*.id');

        expect($second->json('data'))->toHaveCount(1)
            ->and($second->json('meta.next_cursor'))->toBeNull()
            ->and(array_intersect($firstIds, $secondIds))->toBe([])
            ->and([...$firstIds, ...$secondIds])->toEqualCanonicalizing($ids);
    });

    it('honours per_page between 1 and 100 and reflects it in meta', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        for ($i = 0; $i < 3; $i++) {
            listLinksSeed(
                [
                    'destination_url' => 'https://example.com/pp-'.$i,
                    'custom_alias' => 'per-page-'.$i,
                ],
                ['Authorization' => 'Bearer '.$bearer],
            )->assertCreated();
        }

        $response = getListLinks(['per_page' => 2], ['Authorization' => 'Bearer '.$bearer]);
        $response->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('meta.per_page'))->toBe(2)
            ->and($response->json('meta.next_cursor'))->toBeString();
    });

    it('serializes exactly LinkSummary fields and omits destination, ETag, version, blocked_at and user_id', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        listLinksSeed(
            [
                'destination_url' => 'https://example.com/fields-hidden',
                'custom_alias' => 'fields-hidden',
                'title' => 'Hidden Destination',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $response = getListLinks([], ['Authorization' => 'Bearer '.$bearer]);
        $response->assertOk();

        $item = $response->json('data.0');
        // @phpstan-ignore staticMethod.dynamicCall
        $json = (string) $response->getContent();

        expect(array_keys($item))->toEqualCanonicalizing(listLinksSummaryKeys())
            ->and($item)->not->toHaveKey('destination_url')
            ->and($item)->not->toHaveKey('version')
            ->and($item)->not->toHaveKey('blocked_at')
            ->and($item)->not->toHaveKey('user_id')
            ->and($item)->not->toHaveKey('ETag')
            ->and($item)->not->toHaveKey('etag')
            ->and($json)->not->toContain('https://example.com/fields-hidden')
            ->and($json)->not->toContain($owner->id);
    });

    it('does not select destination_url when assembling the list', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        listLinksSeed(
            [
                'destination_url' => 'https://example.com/no-decrypt',
                'custom_alias' => 'no-decrypt',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        getListLinks([], ['Authorization' => 'Bearer '.$bearer])->assertOk();

        foreach ($statements as $sql) {
            expect($sql)->not->toContain('destination_url')
                ->and($sql)->not->toContain('link_destination_versions');
        }
    });
});

describe('GET /api/v1/links search and status', function () {
    it('filters by title substring or slug prefix, case-insensitive and accent-sensitive', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        listLinksSeed(
            [
                'destination_url' => 'https://example.com/title-hit',
                'custom_alias' => 'other-slug',
                'title' => 'Campanha Ação',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();
        listLinksSeed(
            [
                'destination_url' => 'https://example.com/slug-hit',
                'custom_alias' => 'camp-prefix',
                'title' => 'Unrelated',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();
        listLinksSeed(
            [
                'destination_url' => 'https://example.com/miss',
                'custom_alias' => 'zzzzzzzz',
                'title' => 'Nothing',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        $titleHit = getListLinks(['search' => 'AÇÃO'], ['Authorization' => 'Bearer '.$bearer]);
        $titleHit->assertOk();
        expect($titleHit->json('data'))->toHaveCount(1)
            ->and($titleHit->json('data.0.slug'))->toBe('other-slug');

        $accentMiss = getListLinks(['search' => 'acao'], ['Authorization' => 'Bearer '.$bearer]);
        $accentMiss->assertOk();
        expect($accentMiss->json('data'))->toBe([]);

        $slugHit = getListLinks(['search' => 'CAMP'], ['Authorization' => 'Bearer '.$bearer]);
        $slugHit->assertOk();
        expect($slugHit->json('data.*.slug'))->toContain('camp-prefix');
    });

    it('rejects search shorter than 2, longer than 160, or empty after trim', function (string $search) {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        getListLinks(['search' => $search], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    })->with([
        'one char' => ['a'],
        'empty after trim' => ['   '],
        'too long' => [str_repeat('a', 161)],
    ]);

    it('does not apply a text filter when search is absent', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        listLinksSeed(
            [
                'destination_url' => 'https://example.com/no-search',
                'custom_alias' => 'no-search',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        )->assertCreated();

        getListLinks([], ['Authorization' => 'Bearer '.$bearer])
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'no-search');
    });

    it('filters derived status and treats absent or all as every state', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        $active = listLinksSeed(
            [
                'destination_url' => 'https://example.com/active',
                'custom_alias' => 'status-active',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $active->assertCreated();

        $inactive = listLinksSeed(
            [
                'destination_url' => 'https://example.com/inactive',
                'custom_alias' => 'status-off',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $inactive->assertCreated();
        ShortLinkModel::query()->where('id', $inactive->json('data.id'))->update(['is_enabled' => false]);

        $expired = listLinksSeed(
            [
                'destination_url' => 'https://example.com/expired',
                'custom_alias' => 'status-exp',
                'expires_at' => '2026-12-31T00:00:00Z',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $expired->assertCreated();
        ShortLinkModel::query()->where('id', $expired->json('data.id'))->update([
            'expires_at' => '2026-06-01T00:00:00+00:00',
        ]);

        $blocked = listLinksSeed(
            [
                'destination_url' => 'https://example.com/blocked',
                'custom_alias' => 'status-blk',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $blocked->assertCreated();
        ShortLinkModel::query()->where('id', $blocked->json('data.id'))->update([
            'blocked_at' => '2026-06-14T00:00:00+00:00',
        ]);

        $all = getListLinks(['status' => 'all'], ['Authorization' => 'Bearer '.$bearer]);
        $all->assertOk();
        expect($all->json('data'))->toHaveCount(4);

        $absent = getListLinks([], ['Authorization' => 'Bearer '.$bearer]);
        $absent->assertOk();
        expect($absent->json('data'))->toHaveCount(4);

        $activeOnly = getListLinks(['status' => 'active'], ['Authorization' => 'Bearer '.$bearer]);
        $activeOnly->assertOk();
        expect($activeOnly->json('data'))->toHaveCount(1)
            ->and($activeOnly->json('data.0.status'))->toBe('active');

        $inactiveOnly = getListLinks(['status' => 'inactive'], ['Authorization' => 'Bearer '.$bearer]);
        $inactiveOnly->assertOk();
        expect($inactiveOnly->json('data.0.status'))->toBe('inactive');

        $expiredOnly = getListLinks(['status' => 'expired'], ['Authorization' => 'Bearer '.$bearer]);
        $expiredOnly->assertOk();
        expect($expiredOnly->json('data.0.status'))->toBe('expired');

        $blockedOnly = getListLinks(['status' => 'blocked'], ['Authorization' => 'Bearer '.$bearer]);
        $blockedOnly->assertOk();
        expect($blockedOnly->json('data.0.status'))->toBe('blocked');
    });

    it('rejects an unsupported status with 422 VALIDATION_FAILED', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        getListLinks(['status' => 'archived'], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    });
});

describe('GET /api/v1/links cursor', function () {
    it('keeps a cursor valid when only per_page changes and invalid when search or status change', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        for ($i = 0; $i < 3; $i++) {
            listLinksSeed(
                [
                    'destination_url' => 'https://example.com/cursor-'.$i,
                    'custom_alias' => 'cursor-sc-'.$i,
                    'title' => 'Cursor Scope',
                ],
                ['Authorization' => 'Bearer '.$bearer],
            )->assertCreated();
        }

        $first = getListLinks(
            ['search' => 'Cursor', 'status' => 'active', 'per_page' => 1],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $first->assertOk();
        $cursor = $first->json('meta.next_cursor');
        expect($cursor)->toBeString();

        $sameScope = getListLinks(
            ['search' => 'Cursor', 'status' => 'active', 'per_page' => 2, 'cursor' => $cursor],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $sameScope->assertOk();
        expect($sameScope->json('data'))->not->toBeEmpty()
            ->and($sameScope->json('data.0.id'))->not->toBe($first->json('data.0.id'));

        $spy = bindListLinksQuerySpy();
        $changedSearch = getListLinks(
            ['search' => 'Other', 'status' => 'active', 'cursor' => $cursor],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $changedSearch->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.cursor.0.code', 'INVALID_CURSOR');
        expect($spy->listCalls)->toBe(0);

        $changedStatus = getListLinks(
            ['search' => 'Cursor', 'status' => 'inactive', 'cursor' => $cursor],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $changedStatus->assertStatus(422)
            ->assertJsonPath('errors.cursor.0.code', 'INVALID_CURSOR');
        expect($spy->listCalls)->toBe(0);
    });

    it('returns 422 INVALID_CURSOR without querying for empty, malformed, tampered or wrong-anchor cursors', function (string $kind) {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);
        $spy = bindListLinksQuerySpy();

        $validPayload = [
            'v' => 1,
            'anchor' => [
                'created_at' => '2026-06-15T12:00:00Z',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => ['search' => null, 'status' => 'all'],
        ];

        $cursor = match ($kind) {
            'empty' => '',
            'malformed' => 'not-a-cursor',
            'tampered' => listLinksSignCursor($validPayload).'x',
            default => listLinksSignCursor([
                'v' => 1,
                'anchor' => [
                    'created_at' => '2026-06-15T12:00:00Z',
                    'id' => 123,
                ],
                'scope' => ['search' => null, 'status' => 'all'],
            ]),
        };

        getListLinks(['cursor' => $cursor], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('errors.cursor.0.code', 'INVALID_CURSOR');

        expect($spy->listCalls)->toBe(0);
    })->with(['empty', 'malformed', 'tampered', 'wrong-anchor']);
});

describe('GET /api/v1/links validation', function () {
    it('rejects non-integer or out-of-range per_page with 422 VALIDATION_FAILED', function (string $perPage) {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        getListLinks(['per_page' => $perPage], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    })->with([
        'zero' => ['0'],
        'too large' => ['101'],
        'decimal' => ['1.5'],
        'string' => ['abc'],
    ]);
});

describe('GET /api/v1/links authentication and authorization', function () {
    it('returns 401 UNAUTHENTICATED without a bearer', function () {
        getListLinks()
            ->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 401 UNAUTHENTICATED for an invalid bearer', function () {
        getListLinks([], ['Authorization' => 'Bearer not-a-real-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 401 UNAUTHENTICATED for an expired session bearer', function () {
        $owner = listHttpOwner();
        $bearer = listHttpSessionBearer($owner);

        Carbon::setTestNow('2026-06-23T12:00:00+00:00');

        getListLinks([], ['Authorization' => 'Bearer '.$bearer])
            ->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 403 TOKEN_RESTRICTED for a verification bearer without listing', function () {
        $owner = listHttpOwner();
        $bearer = listHttpVerificationBearer($owner);

        listLinksSeed(
            [
                'destination_url' => 'https://example.com/hidden',
                'custom_alias' => 'hidden-ver',
            ],
            ['Authorization' => 'Bearer '.listHttpSessionBearer($owner)],
        )->assertCreated();

        getListLinks([], ['Authorization' => 'Bearer '.$bearer])
            ->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);
    });

    it('returns 403 ACCOUNT_SUSPENDED without listing', function () {
        $owner = UserModel::factory()->create(['status' => UserStatus::Suspended->value]);
        $bearer = listHttpSessionBearer($owner);

        getListLinks([], ['Authorization' => 'Bearer '.$bearer])
            ->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_SUSPENDED);
    });

    it('returns 403 ACCOUNT_PENDING_DELETION without listing', function () {
        $owner = UserModel::factory()->create(['status' => UserStatus::DeletionPending->value]);
        $bearer = listHttpSessionBearer($owner);

        getListLinks([], ['Authorization' => 'Bearer '.$bearer])
            ->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_PENDING_DELETION);
    });

    it('registers GET api/v1/links behind auth.bearer, token.kind session and per-token read throttle', function () {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => in_array('GET', $route->methods(), true)
                && $route->uri() === 'api/v1/links');

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('auth.bearer')
            ->and($route->gatherMiddleware())->toContain('token.kind:session')
            ->and($route->gatherMiddleware())->toContain('throttle.links.read')
            ->and($route->gatherMiddleware())->not->toContain('throttle.links.create');
    });
});
