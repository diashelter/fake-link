<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\CursorCodec;
use Modules\Links\Contracts\Services\CursorSigningKey;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkPageRecords;
use Modules\Links\DTOs\Output\LinkSummaryRecord;
use Modules\Links\Exceptions\InvalidCursor;
use Modules\Links\Exceptions\InvalidCursorReason;
use Modules\Links\Infrastructure\Crypto\ConfigCursorSigningKey;
use Modules\Links\Infrastructure\Pagination\HmacCursorCodec;
use Modules\Links\UseCases\ListLinks;

final class ListLinksFrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class ListLinksFakeQueryRepository implements LinkQueryRepository
{
    public int $listCalls = 0;

    public ?DateTimeImmutable $capturedNow = null;

    public ?CursorAnchor $capturedAnchor = null;

    public function __construct(private LinkPageRecords $page) {}

    public function listForOwner(
        UserId $ownerId,
        ListLinksQuery $query,
        ?CursorAnchor $anchor,
        DateTimeImmutable $now,
    ): LinkPageRecords {
        $this->listCalls++;
        $this->capturedNow = $now;
        $this->capturedAnchor = $anchor;

        return $this->page;
    }

    public function findForOwner(UserId $ownerId, ShortLinkId $linkId): null
    {
        return null;
    }
}

function listLinksOwner(): UserId
{
    return UserId::fromString('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f01');
}

function listLinksSummary(string $id, string $createdAt, string $slug = 'abcd1234'): LinkSummaryRecord
{
    $instant = new DateTimeImmutable($createdAt);

    return new LinkSummaryRecord(
        id: $id,
        slug: $slug,
        slugSource: SlugSource::Automatic,
        title: 'Campaign',
        isEnabled: true,
        status: LinkStatus::Active,
        expiresAt: null,
        createdAt: $instant,
        updatedAt: $instant,
    );
}

function makeListLinks(LinkQueryRepository $queries, ?CursorCodec $cursors = null, ?Clock $clock = null): ListLinks
{
    return new ListLinks(
        queries: $queries,
        cursors: $cursors ?? new HmacCursorCodec(new ConfigCursorSigningKey('unit-test-cursor-hmac-key')),
        clock: $clock ?? new ListLinksFrozenClock(new DateTimeImmutable('2026-09-18T12:00:00Z')),
    );
}

describe('ListLinks', function () {
    it('does not depend on DestinationCipher so listing never decrypts destinations', function () {
        $parameterTypes = array_map(
            static function (ReflectionParameter $parameter): ?string {
                $type = $parameter->getType();

                return $type instanceof ReflectionNamedType ? $type->getName() : null;
            },
            (new ReflectionClass(ListLinks::class))->getConstructor()?->getParameters() ?? [],
        );

        expect($parameterTypes)->not->toContain(DestinationCipher::class)
            ->and($parameterTypes)->not->toContain(CursorSigningKey::class);
    });

    it('emits next_cursor null and default per_page 20 for an empty page', function () {
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords([], false));

        $page = makeListLinks($queries)->execute(listLinksOwner(), ListLinksQuery::from(), null);

        expect($page->items)->toBe([])
            ->and($page->nextCursor)->toBeNull()
            ->and($page->perPage)->toBe(20)
            ->and($queries->listCalls)->toBe(1)
            ->and($queries->capturedAnchor)->toBeNull();
    });

    it('emits next_cursor null when the page is full but hasMore is false', function () {
        $items = [
            listLinksSummary('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f11', '2026-09-18T11:00:00Z'),
            listLinksSummary('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f10', '2026-09-18T10:00:00Z'),
        ];
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords($items, false));

        $page = makeListLinks($queries)->execute(
            listLinksOwner(),
            ListLinksQuery::from(perPage: 2),
            null,
        );

        expect($page->items)->toHaveCount(2)
            ->and($page->nextCursor)->toBeNull()
            ->and($page->perPage)->toBe(2);
    });

    it('emits next_cursor only when another page exists and anchors the last returned item', function () {
        $lastId = '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f10';
        $lastCreated = '2026-09-18T10:00:00Z';
        $items = [
            listLinksSummary('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f11', '2026-09-18T11:00:00Z'),
            listLinksSummary($lastId, $lastCreated),
        ];
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords($items, true));
        $codec = new HmacCursorCodec(new ConfigCursorSigningKey('unit-test-cursor-hmac-key'));
        $query = ListLinksQuery::from(perPage: 2, search: 'camp', status: 'active');

        $page = makeListLinks($queries, $codec)->execute(listLinksOwner(), $query, null);

        expect($page->nextCursor)->not->toBeNull()
            ->and($page->perPage)->toBe(2);

        $anchor = $codec->decode($page->nextCursor, $query->scope());

        expect($anchor->id->value())->toBe($lastId)
            ->and($anchor->createdAt->format('Y-m-d\TH:i:s\Z'))->toBe($lastCreated);
    });

    it('keeps next_cursor valid when only per_page changes and invalidates it when search or status change', function () {
        $items = [
            listLinksSummary('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f11', '2026-09-18T11:00:00Z'),
        ];
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords($items, true));
        $codec = new HmacCursorCodec(new ConfigCursorSigningKey('unit-test-cursor-hmac-key'));
        $original = ListLinksQuery::from(perPage: 1, search: 'camp', status: 'active');

        $page = makeListLinks($queries, $codec)->execute(listLinksOwner(), $original, null);

        $sameScopeDifferentSize = $codec->decode(
            $page->nextCursor,
            ListLinksQuery::from(perPage: 50, search: 'camp', status: 'active')->scope(),
        );

        expect($sameScopeDifferentSize->id->value())->toBe('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f11');

        expect(fn () => $codec->decode(
            $page->nextCursor,
            ListLinksQuery::from(perPage: 1, search: 'other', status: 'active')->scope(),
        ))->toThrow(InvalidCursor::class);

        expect(fn () => $codec->decode(
            $page->nextCursor,
            ListLinksQuery::from(perPage: 1, search: 'camp', status: 'expired')->scope(),
        ))->toThrow(InvalidCursor::class);
    });

    it('does not query when the cursor is malformed', function () {
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords([], false));

        expect(fn () => makeListLinks($queries)->execute(
            listLinksOwner(),
            ListLinksQuery::from(),
            'not-a-cursor',
        ))->toThrow(InvalidCursor::class);

        expect($queries->listCalls)->toBe(0);
    });

    it('does not query when the cursor is an empty string', function () {
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords([], false));

        try {
            makeListLinks($queries)->execute(listLinksOwner(), ListLinksQuery::from(), '');
            expect(false)->toBeTrue();
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::Malformed)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR')
                ->and($queries->listCalls)->toBe(0);
        }
    });

    it('passes a single captured UTC now from the clock into the repository', function () {
        $now = new DateTimeImmutable('2026-09-18T15:30:00', new DateTimeZone('UTC'));
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords([], false));

        makeListLinks($queries, clock: new ListLinksFrozenClock($now))
            ->execute(listLinksOwner(), ListLinksQuery::from(), null);

        expect($queries->capturedNow)->toBe($now)
            ->and($queries->capturedNow?->getTimezone()->getName())->toBe('UTC');
    });

    it('decodes a valid cursor before listing so the following page is strictly after the anchor', function () {
        $codec = new HmacCursorCodec(new ConfigCursorSigningKey('unit-test-cursor-hmac-key'));
        $query = ListLinksQuery::from();
        $anchor = new CursorAnchor(
            new DateTimeImmutable('2026-09-18T11:00:00Z'),
            ShortLinkId::fromString('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f11'),
        );
        $cursor = $codec->encode($anchor, $query->scope());
        $queries = new ListLinksFakeQueryRepository(new LinkPageRecords([], false));

        makeListLinks($queries, $codec)->execute(listLinksOwner(), $query, $cursor);

        expect($queries->capturedAnchor)->not->toBeNull()
            ->and($queries->capturedAnchor?->id->value())->toBe($anchor->id->value())
            ->and($queries->capturedAnchor?->createdAt->format('Y-m-d\TH:i:s\Z'))->toBe('2026-09-18T11:00:00Z');
    });
});
