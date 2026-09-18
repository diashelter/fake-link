<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\Exceptions\DestinationDecryptionFailed;
use Modules\Links\Infrastructure\Http\Responses\LinkCreationSnapshotFactory;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentLinkQueryRepository;
use Modules\Links\Infrastructure\Time\SystemClock;
use Modules\Links\UseCases\CreateLink;
use Modules\Links\UseCases\GetLink;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated(
        (string) config('database.connections.pgsql.database'),
    );
});

final class GetLinkIntegrationClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class GetLinkCountingCipher implements DestinationCipher
{
    public int $decryptCalls = 0;

    public function __construct(private DestinationCipher $inner) {}

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

function getLinkInsertUser(string $email): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Get Link User',
        'email' => $email,
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return UserId::fromString($userId);
}

function makeGetLinkUseCase(Clock $clock, ?DestinationCipher $cipher = null): GetLink
{
    return new GetLink(
        queries: new EloquentLinkQueryRepository,
        cipher: $cipher ?? app(DestinationCipher::class),
        effectiveStatus: new EffectiveStatus,
        etag: app(LinkETag::class),
        clock: $clock,
    );
}

/**
 * @param  array{
 *     slug: string,
 *     title?: string|null,
 *     is_enabled?: bool,
 *     blocked_at?: string|null,
 *     expires_at?: string|null,
 *     created_at: string
 * }  $attrs
 */
function getLinkInsertEncrypted(UserId $owner, array $attrs, DestinationUrl $destination): string
{
    $id = (string) Str::uuid7();
    $createdAt = $attrs['created_at'];
    $encrypted = app(DestinationCipher::class)->encrypt($destination);

    SlugReservationModel::query()->create([
        'slug' => $attrs['slug'],
        'reserved_at' => $createdAt,
    ]);

    DB::table('short_links')->insert([
        'id' => $id,
        'user_id' => $owner->value(),
        'slug' => $attrs['slug'],
        'slug_source' => 'automatic',
        'title' => $attrs['title'] ?? null,
        'is_enabled' => $attrs['is_enabled'] ?? true,
        'blocked_at' => $attrs['blocked_at'] ?? null,
        'expires_at' => $attrs['expires_at'] ?? null,
        'version' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    LinkDestinationVersionModel::query()->create([
        'id' => (string) Str::uuid7(),
        'short_link_id' => $id,
        'destination_url' => $encrypted->envelope(),
        'key_id' => $encrypted->keyId(),
        'valid_from' => $createdAt,
        'valid_to' => null,
    ]);

    return $id;
}

describe('GetLink integration', function () {
    it('returns the same ETag as creation for a newly created owner link', function () {
        $owner = getLinkInsertUser('get-etag@example.com');
        $created = app(CreateLink::class)->execute($owner, new CreateLinkInput(
            destinationUrl: 'https://example.com/created',
            customAlias: null,
            title: 'Created',
            expiresAt: null,
        ));
        $creationEtag = app(LinkCreationSnapshotFactory::class)->fromCreated($created)->headers['ETag'];

        $result = makeGetLinkUseCase(new SystemClock)->execute(
            $owner,
            ShortLinkId::fromString($created->id),
        );

        expect($result)->not->toBeNull()
            ->and($result?->link->destinationUrl)->toBe('https://example.com/created')
            ->and($result?->link->id)->toBe($created->id)
            ->and($result?->link->slug)->toBe($created->slug)
            ->and($result?->etag)->toBe($creationEtag)
            ->and($result?->etag)->toMatch('/^"[^"]+"$/');
    });

    it('returns null without decrypting a missing or foreign link', function () {
        $owner = getLinkInsertUser('get-owner@example.com');
        $stranger = getLinkInsertUser('get-stranger@example.com');
        $destination = DestinationUrl::fromString('https://example.com/private', app(PublicHostClassifier::class));
        $id = getLinkInsertEncrypted($owner, [
            'slug' => 'owned001',
            'created_at' => '2026-09-18T11:00:00Z',
        ], $destination);
        $cipher = new GetLinkCountingCipher(app(DestinationCipher::class));
        $useCase = makeGetLinkUseCase(new SystemClock, $cipher);

        $missing = $useCase->execute($owner, ShortLinkId::fromString('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99'));
        $foreign = $useCase->execute($stranger, ShortLinkId::fromString($id));

        expect($missing)->toBeNull()
            ->and($foreign)->toBeNull()
            ->and($cipher->decryptCalls)->toBe(0);
    });

    it('does not produce a partial result when the destination envelope is corrupted', function () {
        $owner = getLinkInsertUser('get-corrupt@example.com');
        $destination = DestinationUrl::fromString('https://example.com/secret', app(PublicHostClassifier::class));
        $id = getLinkInsertEncrypted($owner, [
            'slug' => 'corrupt1',
            'created_at' => '2026-09-18T11:00:00Z',
        ], $destination);

        DB::table('link_destination_versions')
            ->where('short_link_id', $id)
            ->update(['destination_url' => '!!!not-a-valid-envelope!!!']);

        $result = null;
        $caught = null;

        try {
            $result = makeGetLinkUseCase(new SystemClock)->execute(
                $owner,
                ShortLinkId::fromString($id),
            );
        } catch (DestinationDecryptionFailed $exception) {
            $caught = $exception;
        }

        expect($caught)->toBeInstanceOf(DestinationDecryptionFailed::class)
            ->and($result)->toBeNull();
    });

    it('computes temporal effective status from the captured now of this request', function () {
        $owner = getLinkInsertUser('get-temporal@example.com');
        $destination = DestinationUrl::fromString('https://example.com/expires', app(PublicHostClassifier::class));
        $id = getLinkInsertEncrypted($owner, [
            'slug' => 'temporal',
            'expires_at' => '2026-09-18T12:00:00Z',
            'created_at' => '2026-09-18T10:00:00Z',
        ], $destination);

        $before = makeGetLinkUseCase(new GetLinkIntegrationClock(new DateTimeImmutable('2026-09-18T11:59:59Z')))
            ->execute($owner, ShortLinkId::fromString($id));
        $onExpiry = makeGetLinkUseCase(new GetLinkIntegrationClock(new DateTimeImmutable('2026-09-18T12:00:00Z')))
            ->execute($owner, ShortLinkId::fromString($id));

        expect($before?->link->status)->toBe(LinkStatus::Active)
            ->and($before?->link->destinationUrl)->toBe('https://example.com/expires')
            ->and($onExpiry?->link->status)->toBe(LinkStatus::Expired)
            ->and($onExpiry?->etag)->not->toBe($before?->etag);
    });
});
