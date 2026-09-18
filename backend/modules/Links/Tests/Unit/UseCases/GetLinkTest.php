<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkPageRecords;
use Modules\Links\DTOs\Output\PersistedLinkDetail;
use Modules\Links\Exceptions\DestinationDecryptionFailed;
use Modules\Links\UseCases\GetLink;

final class GetLinkFrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class GetLinkFixedETagKey implements ETagSigningKey
{
    public function __construct(private readonly string $key) {}

    public function value(): string
    {
        return $this->key;
    }
}

final class GetLinkRecordingCipher implements DestinationCipher
{
    public int $decryptCalls = 0;

    public function __construct(
        private readonly ?DestinationUrl $plaintext = null,
        private readonly ?DestinationDecryptionFailed $failure = null,
    ) {}

    public function encrypt(DestinationUrl $url): EncryptedDestination
    {
        throw new RuntimeException('encrypt is not used by GetLink.');
    }

    public function decrypt(EncryptedDestination $envelope): DestinationUrl
    {
        $this->decryptCalls++;

        if ($this->failure instanceof DestinationDecryptionFailed) {
            throw $this->failure;
        }

        return $this->plaintext ?? throw new RuntimeException('plaintext not configured');
    }
}

final class GetLinkFakeQueryRepository implements LinkQueryRepository
{
    public int $findCalls = 0;

    public function __construct(private readonly ?PersistedLinkDetail $detail = null) {}

    public function listForOwner(
        UserId $ownerId,
        ListLinksQuery $query,
        ?CursorAnchor $anchor,
        DateTimeImmutable $now,
    ): LinkPageRecords {
        return new LinkPageRecords([], false);
    }

    public function findForOwner(UserId $ownerId, ShortLinkId $linkId): ?PersistedLinkDetail
    {
        $this->findCalls++;

        return $this->detail;
    }
}

function getLinkOwner(): UserId
{
    return UserId::fromString('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f01');
}

function getLinkId(): ShortLinkId
{
    return ShortLinkId::fromString('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f22');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function getLinkDetail(array $overrides = []): PersistedLinkDetail
{
    $createdAt = new DateTimeImmutable('2026-09-17T10:00:00Z');

    return new PersistedLinkDetail(
        id: $overrides['id'] ?? getLinkId(),
        slug: $overrides['slug'] ?? Slug::fromGenerated('abcd1234'),
        slugSource: $overrides['slugSource'] ?? SlugSource::Automatic,
        destination: $overrides['destination'] ?? EncryptedDestination::fromParts('sealed-envelope', 'testing-key-1'),
        title: array_key_exists('title', $overrides) ? $overrides['title'] : 'Launch',
        isEnabled: $overrides['isEnabled'] ?? true,
        blockedAt: $overrides['blockedAt'] ?? null,
        expiresAt: array_key_exists('expiresAt', $overrides) ? $overrides['expiresAt'] : null,
        version: $overrides['version'] ?? 1,
        createdAt: $overrides['createdAt'] ?? $createdAt,
        updatedAt: $overrides['updatedAt'] ?? $createdAt,
    );
}

function getLinkPlaintext(): DestinationUrl
{
    return DestinationUrl::fromString('https://example.com/path', new PublicHostClassifier([]));
}

function makeGetLink(
    LinkQueryRepository $queries,
    DestinationCipher $cipher,
    ?Clock $clock = null,
    ?LinkETag $etag = null,
): GetLink {
    return new GetLink(
        queries: $queries,
        cipher: $cipher,
        effectiveStatus: new EffectiveStatus,
        etag: $etag ?? new LinkETag(new GetLinkFixedETagKey('unit-test-etag-hmac-key')),
        clock: $clock ?? new GetLinkFrozenClock(new DateTimeImmutable('2026-09-18T12:00:00Z')),
    );
}

describe('GetLink', function () {
    it('returns null and does not decrypt when the owner-scoped lookup misses', function () {
        $queries = new GetLinkFakeQueryRepository(null);
        $cipher = new GetLinkRecordingCipher(plaintext: getLinkPlaintext());

        $result = makeGetLink($queries, $cipher)->execute(getLinkOwner(), getLinkId());

        expect($result)->toBeNull()
            ->and($queries->findCalls)->toBe(1)
            ->and($cipher->decryptCalls)->toBe(0);
    });

    it('decrypts only after an owner-scoped row is found and returns destination plus ETag of the creation tuple', function () {
        $row = getLinkDetail();
        $queries = new GetLinkFakeQueryRepository($row);
        $plaintext = getLinkPlaintext();
        $cipher = new GetLinkRecordingCipher(plaintext: $plaintext);
        $etag = new LinkETag(new GetLinkFixedETagKey('unit-test-etag-hmac-key'));
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $status = (new EffectiveStatus)->for($row->blockedAt, $row->expiresAt, $row->isEnabled, $now);

        $result = makeGetLink($queries, $cipher, new GetLinkFrozenClock($now), $etag)
            ->execute(getLinkOwner(), getLinkId());

        expect($cipher->decryptCalls)->toBe(1)
            ->and($result)->not->toBeNull()
            ->and($result?->link->destinationUrl)->toBe('https://example.com/path')
            ->and($result?->link->id)->toBe($row->id->value())
            ->and($result?->link->slug)->toBe('abcd1234')
            ->and($result?->link->title)->toBe('Launch')
            ->and($result?->link->status)->toBe(LinkStatus::Active)
            ->and($result?->etag)->toBe($etag->for(
                id: $row->id->value(),
                slug: $row->slug->value(),
                normalizedDestinationUrl: $plaintext->value(),
                title: $row->title,
                isEnabled: $row->isEnabled,
                expiresAt: $row->expiresAt,
                blockedAt: $row->blockedAt,
                updatedAt: $row->updatedAt,
                effectiveStatus: $status,
            ))
            ->and((new ReflectionClass($result->link))->hasProperty('version'))->toBeFalse()
            ->and((new ReflectionClass($result->link))->hasProperty('blockedAt'))->toBeFalse()
            ->and((new ReflectionClass($result->link))->hasProperty('userId'))->toBeFalse();
    });

    it('does not produce a partial DTO when destination decryption fails', function () {
        $queries = new GetLinkFakeQueryRepository(getLinkDetail());
        $cipher = new GetLinkRecordingCipher(failure: DestinationDecryptionFailed::tampered());

        $result = null;
        $caught = null;

        try {
            $result = makeGetLink($queries, $cipher)->execute(getLinkOwner(), getLinkId());
        } catch (DestinationDecryptionFailed $exception) {
            $caught = $exception;
        }

        expect($caught)->toBeInstanceOf(DestinationDecryptionFailed::class)
            ->and($caught?->getMessage())->toBe('Destination decryption failed: authentication tag verification failed.')
            ->and($result)->toBeNull()
            ->and($cipher->decryptCalls)->toBe(1);
    });

    it('derives expired status from expires_at <= captured now and blocked takes precedence', function () {
        $now = new DateTimeImmutable('2026-09-18T12:00:00Z');
        $expiredRow = getLinkDetail([
            'expiresAt' => new DateTimeImmutable('2026-09-18T12:00:00Z'),
        ]);
        $blockedRow = getLinkDetail([
            'blockedAt' => new DateTimeImmutable('2026-09-18T11:00:00Z'),
            'expiresAt' => new DateTimeImmutable('2026-09-18T11:30:00Z'),
            'isEnabled' => false,
        ]);

        $expired = makeGetLink(
            new GetLinkFakeQueryRepository($expiredRow),
            new GetLinkRecordingCipher(plaintext: getLinkPlaintext()),
            new GetLinkFrozenClock($now),
        )->execute(getLinkOwner(), getLinkId());

        $blocked = makeGetLink(
            new GetLinkFakeQueryRepository($blockedRow),
            new GetLinkRecordingCipher(plaintext: getLinkPlaintext()),
            new GetLinkFrozenClock($now),
        )->execute(getLinkOwner(), getLinkId());

        expect($expired?->link->status)->toBe(LinkStatus::Expired)
            ->and($blocked?->link->status)->toBe(LinkStatus::Blocked);
    });

    it('derives inactive when disabled and not blocked or expired', function () {
        $row = getLinkDetail(['isEnabled' => false]);

        $result = makeGetLink(
            new GetLinkFakeQueryRepository($row),
            new GetLinkRecordingCipher(plaintext: getLinkPlaintext()),
            new GetLinkFrozenClock(new DateTimeImmutable('2026-09-18T12:00:00Z')),
        )->execute(getLinkOwner(), getLinkId());

        expect($result?->link->status)->toBe(LinkStatus::Inactive)
            ->and($result?->link->isEnabled)->toBeFalse();
    });
});
