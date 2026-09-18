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
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\UseCases\GetLink;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    Carbon::setTestNow('2026-06-15T12:00:00+00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

final class GetLinkHttpCipherSpy implements DestinationCipher
{
    public int $decryptCalls = 0;

    public function __construct(private readonly DestinationCipher $inner) {}

    public function encrypt(DestinationUrl $url): EncryptedDestination
    {
        return $this->inner->encrypt($url);
    }

    public function decrypt(EncryptedDestination $envelope): DestinationUrl
    {
        $this->decryptCalls++;

        return $this->inner->decrypt($envelope);
    }
}

function getHttpOwner(): UserModel
{
    return UserModel::factory()->active()->create();
}

function getHttpSessionBearer(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function getHttpVerificationBearer(UserModel $user): string
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
function seedGetLink(array $payload, array $headers): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->postJson('/api/v1/links', $payload, $headers);
    assert($response instanceof TestResponse);

    return $response;
}

/**
 * @param  array<string, string>  $headers
 * @return TestResponse<JsonResponse>
 */
function getLinkDetailHttp(string $linkId, array $headers = []): TestResponse
{
    // @phpstan-ignore method.notFound
    $response = test()->getJson('/api/v1/links/'.$linkId, $headers);
    assert($response instanceof TestResponse);

    /** @var TestResponse<JsonResponse> $response */
    return $response;
}

function bindGetLinkCipherSpy(): GetLinkHttpCipherSpy
{
    $spy = new GetLinkHttpCipherSpy(app(DestinationCipher::class));
    app()->instance(DestinationCipher::class, $spy);
    app()->forgetInstance(GetLink::class);

    return $spy;
}

/**
 * @return list<string>
 */
function getLinkDetailKeys(): array
{
    return [
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
    ];
}

describe('GET /api/v1/links/{link} happy path', function () {
    it('returns 200 LinkDetail with private cache, request id and the creation ETag', function () {
        $owner = getHttpOwner();
        $bearer = getHttpSessionBearer($owner);

        $created = seedGetLink(
            [
                'destination_url' => 'https://example.com/detail-owner',
                'custom_alias' => 'detail-own',
                'title' => 'Owner Detail',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $created->assertCreated();

        $response = getLinkDetailHttp($created->json('data.id'), ['Authorization' => 'Bearer '.$bearer]);

        $response->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'))
            ->assertJsonPath('data.destination_url', 'https://example.com/detail-owner')
            ->assertJsonPath('data.slug', 'detail-own')
            ->assertJsonPath('data.title', 'Owner Detail');

        expect($response->headers->get('Cache-Control'))->toContain('private')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Request-ID'))->not->toBeEmpty()
            ->and($response->headers->get('ETag'))->toBe($created->headers->get('ETag'))
            ->and($response->headers->get('ETag'))->toMatch('/^"[0-9a-f]{64}"$/')
            ->and($response->headers->get('Location'))->toBeNull();
    });

    it('serializes exactly LinkSummary fields plus destination_url', function () {
        $owner = getHttpOwner();
        $bearer = getHttpSessionBearer($owner);

        $created = seedGetLink(
            [
                'destination_url' => 'https://example.com/detail-fields',
                'custom_alias' => 'detail-fld',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $created->assertCreated();

        $response = getLinkDetailHttp($created->json('data.id'), ['Authorization' => 'Bearer '.$bearer]);
        $response->assertOk();

        $item = $response->json('data');
        // @phpstan-ignore staticMethod.dynamicCall
        $json = (string) $response->getContent();

        expect(array_keys($item))->toEqualCanonicalizing(getLinkDetailKeys())
            ->and($item)->not->toHaveKey('version')
            ->and($item)->not->toHaveKey('blocked_at')
            ->and($item)->not->toHaveKey('user_id')
            ->and($json)->not->toContain($owner->id);
    });
});

describe('GET /api/v1/links/{link} ownership', function () {
    it('returns identical 404 RESOURCE_NOT_FOUND for missing and foreign links without decrypting', function () {
        $owner = getHttpOwner();
        $stranger = getHttpOwner();
        $ownerBearer = getHttpSessionBearer($owner);
        $strangerBearer = getHttpSessionBearer($stranger);

        $created = seedGetLink(
            [
                'destination_url' => 'https://example.com/secret-owner',
                'custom_alias' => 'secret-own',
            ],
            ['Authorization' => 'Bearer '.$ownerBearer],
        );
        $created->assertCreated();

        $missingId = '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99';
        $spy = bindGetLinkCipherSpy();

        $missing = getLinkDetailHttp($missingId, ['Authorization' => 'Bearer '.$ownerBearer]);
        $foreign = getLinkDetailHttp($created->json('data.id'), ['Authorization' => 'Bearer '.$strangerBearer]);

        $missing->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $foreign->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

        expect($missing->json('message'))->toBe($foreign->json('message'))
            ->and($missing->json('code'))->toBe($foreign->json('code'))
            ->and($missing->json())->not->toHaveKey('data')
            ->and($foreign->json())->not->toHaveKey('data')
            ->and($missing->headers->get('ETag'))->toBeNull()
            ->and($foreign->headers->get('ETag'))->toBeNull()
            ->and($missing->headers->get('Cache-Control'))->toContain('no-store')
            ->and($missing->headers->get('X-Request-ID'))->not->toBeEmpty()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and((string) $missing->getContent())->not->toContain('https://example.com/secret-owner')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and((string) $foreign->getContent())->not->toContain('https://example.com/secret-owner')
            ->and($spy->decryptCalls)->toBe(0);
    });

    it('returns 404 RESOURCE_NOT_FOUND for a non-v7 identifier without querying destination', function () {
        $owner = getHttpOwner();
        $bearer = getHttpSessionBearer($owner);
        $spy = bindGetLinkCipherSpy();

        $response = getLinkDetailHttp('not-a-uuid', ['Authorization' => 'Bearer '.$bearer]);

        $response->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertJsonMissingPath('data');

        expect($spy->decryptCalls)->toBe(0)
            ->and($response->headers->get('ETag'))->toBeNull();
    });
});

describe('GET /api/v1/links/{link} decrypt failure', function () {
    it('returns 503 SERVICE_UNAVAILABLE without data, ETag or destination when the envelope is corrupt', function () {
        $owner = getHttpOwner();
        $bearer = getHttpSessionBearer($owner);

        $created = seedGetLink(
            [
                'destination_url' => 'https://example.com/corrupt-dest',
                'custom_alias' => 'corrupt-d',
            ],
            ['Authorization' => 'Bearer '.$bearer],
        );
        $created->assertCreated();
        $linkId = $created->json('data.id');

        DB::table('link_destination_versions')
            ->where('short_link_id', $linkId)
            ->update(['destination_url' => '!!!not-a-valid-envelope!!!']);

        $response = getLinkDetailHttp($linkId, ['Authorization' => 'Bearer '.$bearer]);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
            ->assertJsonMissingPath('data');

        expect($response->json())->not->toHaveKey('data')
            ->and($response->headers->get('ETag'))->toBeNull()
            // @phpstan-ignore staticMethod.dynamicCall
            ->and((string) $response->getContent())->not->toContain('https://example.com/corrupt-dest')
            // @phpstan-ignore staticMethod.dynamicCall
            ->and((string) $response->getContent())->not->toContain('!!!not-a-valid-envelope!!!')
            ->and($response->headers->get('X-Request-ID'))->not->toBeEmpty()
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('GET /api/v1/links/{link} authentication and authorization', function () {
    it('returns 401 UNAUTHENTICATED without a bearer', function () {
        getLinkDetailHttp('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99')
            ->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 401 UNAUTHENTICATED for an invalid bearer', function () {
        getLinkDetailHttp('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99', [
            'Authorization' => 'Bearer not-a-real-token',
        ])->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 401 UNAUTHENTICATED for an expired session bearer', function () {
        $owner = getHttpOwner();
        $bearer = getHttpSessionBearer($owner);

        Carbon::setTestNow('2026-06-23T12:00:00+00:00');

        getLinkDetailHttp('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertUnauthorized()
            ->assertJsonPath('code', AuthTokenException::UNAUTHENTICATED);
    });

    it('returns 403 TOKEN_RESTRICTED for a verification bearer without reading the link', function () {
        $owner = getHttpOwner();
        $session = getHttpSessionBearer($owner);
        $created = seedGetLink(
            [
                'destination_url' => 'https://example.com/hidden-detail',
                'custom_alias' => 'hidden-dt',
            ],
            ['Authorization' => 'Bearer '.$session],
        );
        $created->assertCreated();

        $spy = bindGetLinkCipherSpy();
        $response = getLinkDetailHttp($created->json('data.id'), [
            'Authorization' => 'Bearer '.getHttpVerificationBearer($owner),
        ]);

        $response->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::TOKEN_RESTRICTED);

        expect($spy->decryptCalls)->toBe(0);
    });

    it('returns 403 ACCOUNT_SUSPENDED without reading the link', function () {
        $owner = UserModel::factory()->create(['status' => UserStatus::Suspended->value]);
        $bearer = getHttpSessionBearer($owner);

        getLinkDetailHttp('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_SUSPENDED);
    });

    it('returns 403 ACCOUNT_PENDING_DELETION without reading the link', function () {
        $owner = UserModel::factory()->create(['status' => UserStatus::DeletionPending->value]);
        $bearer = getHttpSessionBearer($owner);

        getLinkDetailHttp('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99', [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertForbidden()
            ->assertJsonPath('code', AuthTokenException::ACCOUNT_PENDING_DELETION);
    });

    it('registers GET api/v1/links/{link} behind auth.bearer, session kind and shared read throttle', function () {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => in_array('GET', $route->methods(), true)
                && $route->uri() === 'api/v1/links/{link}');

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('auth.bearer')
            ->and($route->gatherMiddleware())->toContain('token.kind:session')
            ->and($route->gatherMiddleware())->toContain('throttle.links.read')
            ->and($route->gatherMiddleware())->not->toContain('throttle.links.create');
    });
});
