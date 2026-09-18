<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkSummaryRecord;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentLinkQueryRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const QUERY_REPO_NOW = '2026-09-18T12:00:00Z';
const QUERY_REPO_DESTINATION_SENTINEL = 'ciphertext-must-not-appear-in-list';

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated(
        (string) config('database.connections.pgsql.database'),
    );
    $this->queries = new EloquentLinkQueryRepository;
    $this->now = new DateTimeImmutable(QUERY_REPO_NOW);
});

function queryRepoInsertUser(string $email): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Query Repo User',
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

/**
 * @param  array{
 *     id?: string,
 *     slug: string,
 *     title?: string|null,
 *     is_enabled?: bool,
 *     blocked_at?: string|null,
 *     expires_at?: string|null,
 *     created_at: string,
 *     slug_source?: string
 * }  $attrs
 */
function queryRepoInsertLink(UserId $owner, array $attrs): string
{
    $id = $attrs['id'] ?? (string) Str::uuid7();
    $createdAt = $attrs['created_at'];

    SlugReservationModel::query()->create([
        'slug' => $attrs['slug'],
        'reserved_at' => $createdAt,
    ]);

    DB::table('short_links')->insert([
        'id' => $id,
        'user_id' => $owner->value(),
        'slug' => $attrs['slug'],
        'slug_source' => $attrs['slug_source'] ?? 'automatic',
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
        'destination_url' => QUERY_REPO_DESTINATION_SENTINEL,
        'key_id' => 'testing-key-1',
        'valid_from' => $createdAt,
        'valid_to' => null,
    ]);

    return $id;
}

/**
 * @return list<string>
 */
function queryRepoListIds(LinkQueryRepository $repo, UserId $owner, ListLinksQuery $query, ?CursorAnchor $anchor = null): array
{
    $page = $repo->listForOwner($owner, $query, $anchor, new DateTimeImmutable(QUERY_REPO_NOW));

    return array_map(fn (LinkSummaryRecord $item) => $item->id, $page->items);
}

describe('LinkQueryRepository port', function () {
    it('is a separate read port and does not add list or detail methods to ShortLinkRepository', function () {
        $writeMethods = collect((new ReflectionClass(ShortLinkRepository::class))->getMethods())
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->sort()
            ->values()
            ->all();
        $readMethods = collect((new ReflectionClass(LinkQueryRepository::class))->getMethods())
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->sort()
            ->values()
            ->all();
        $writeAdapter = collect((new ReflectionClass(EloquentShortLinkRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === EloquentShortLinkRepository::class)
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->sort()
            ->values()
            ->all();

        expect($writeMethods)->toBe(['create'])
            ->and($readMethods)->toBe(['findForOwner', 'listForOwner'])
            ->and($writeAdapter)->toBe(['__construct', 'create']);
    });
});

describe('EloquentLinkQueryRepository::listForOwner', function () {
    it('returns an empty page when the owner has no links', function () {
        $owner = queryRepoInsertUser('query-empty@example.com');

        $page = $this->queries->listForOwner($owner, ListLinksQuery::from(), null, $this->now);

        expect($page->items)->toBe([])
            ->and($page->hasMore)->toBeFalse();
    });

    it('orders by created_at descending and UUID v7 id descending for the same timestamp', function () {
        $owner = queryRepoInsertUser('query-order@example.com');
        $older = queryRepoInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f01',
            'slug' => 'older001',
            'created_at' => '2026-09-17 12:00:00+00',
        ]);
        $laterId = queryRepoInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99',
            'slug' => 'tiehigh1',
            'created_at' => '2026-09-18 10:00:00+00',
        ]);
        $earlierId = queryRepoInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f10',
            'slug' => 'tielow01',
            'created_at' => '2026-09-18 10:00:00+00',
        ]);

        $ids = queryRepoListIds($this->queries, $owner, ListLinksQuery::from());

        expect($ids)->toBe([$laterId, $earlierId, $older]);
    });

    it('never returns another owner\'s links', function () {
        $owner = queryRepoInsertUser('query-owner@example.com');
        $stranger = queryRepoInsertUser('query-stranger@example.com');
        $mine = queryRepoInsertLink($owner, [
            'slug' => 'mine0001',
            'created_at' => '2026-09-18 10:00:00+00',
        ]);
        queryRepoInsertLink($stranger, [
            'slug' => 'other001',
            'created_at' => '2026-09-18 11:00:00+00',
        ]);

        $ids = queryRepoListIds($this->queries, $owner, ListLinksQuery::from());

        expect($ids)->toBe([$mine]);
    });

    it('applies owner, search, status, and keyset before the page limit', function () {
        $owner = queryRepoInsertUser('query-limit@example.com');
        $keep = [];
        for ($i = 5; $i >= 1; $i--) {
            $keep[] = queryRepoInsertLink($owner, [
                'slug' => 'keep000'.$i,
                'title' => 'Campanha '.$i,
                'created_at' => sprintf('2026-09-18 10:0%d:00+00', $i),
            ]);
        }
        queryRepoInsertLink($owner, [
            'slug' => 'skipinac',
            'title' => 'Campanha skip',
            'is_enabled' => false,
            'created_at' => '2026-09-18 10:06:00+00',
        ]);

        $query = ListLinksQuery::from(perPage: 2, search: 'campanha', status: 'active');
        $first = $this->queries->listForOwner($owner, $query, null, $this->now);

        expect(array_map(fn (LinkSummaryRecord $item) => $item->id, $first->items))->toBe([$keep[0], $keep[1]])
            ->and($first->hasMore)->toBeTrue()
            ->and($first->items[0]->status)->toBe(LinkStatus::Active)
            ->and($first->items[1]->status)->toBe(LinkStatus::Active);

        $anchor = new CursorAnchor(
            createdAt: $first->items[1]->createdAt,
            id: ShortLinkId::fromString($first->items[1]->id),
        );
        $second = $this->queries->listForOwner($owner, $query, $anchor, $this->now);

        expect(array_map(fn (LinkSummaryRecord $item) => $item->id, $second->items))->toBe([$keep[2], $keep[3]])
            ->and($second->hasMore)->toBeTrue();
    });

    it('continues keyset pagination when the signed anchor row has been removed', function () {
        $owner = queryRepoInsertUser('query-missing-anchor@example.com');
        $first = queryRepoInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0a01',
            'slug' => 'pageone1',
            'created_at' => '2026-09-18 10:03:00+00',
        ]);
        $removed = queryRepoInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0a02',
            'slug' => 'removed1',
            'created_at' => '2026-09-18 10:02:00+00',
        ]);
        $third = queryRepoInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0a03',
            'slug' => 'pagetwo1',
            'created_at' => '2026-09-18 10:01:00+00',
        ]);

        DB::table('link_destination_versions')->where('short_link_id', $removed)->delete();
        DB::table('short_links')->where('id', $removed)->delete();

        $page = $this->queries->listForOwner(
            $owner,
            ListLinksQuery::from(perPage: 10),
            new CursorAnchor(
                createdAt: new DateTimeImmutable('2026-09-18T10:02:00Z'),
                id: ShortLinkId::fromString($removed),
            ),
            $this->now,
        );

        expect(array_map(fn (LinkSummaryRecord $item) => $item->id, $page->items))->toBe([$third])
            ->and($page->items[0]->id)->not->toBe($first);
    });

    it('matches title substring or slug prefix, ignoring case and keeping accents', function () {
        $owner = queryRepoInsertUser('query-search@example.com');
        $titleHit = queryRepoInsertLink($owner, [
            'slug' => 'zzzzzzzz',
            'title' => 'Campanha especial',
            'created_at' => '2026-09-18 10:04:00+00',
        ]);
        $slugHit = queryRepoInsertLink($owner, [
            'slug' => 'campanha',
            'title' => null,
            'created_at' => '2026-09-18 10:03:00+00',
        ]);
        $accentHit = queryRepoInsertLink($owner, [
            'slug' => 'accent01',
            'title' => 'ação',
            'created_at' => '2026-09-18 10:02:00+00',
        ]);
        queryRepoInsertLink($owner, [
            'slug' => 'nomatch1',
            'title' => 'Outro título',
            'created_at' => '2026-09-18 10:01:00+00',
        ]);

        $byTitleOrSlug = queryRepoListIds($this->queries, $owner, ListLinksQuery::from(search: 'CAMP'));
        $byAccent = queryRepoListIds($this->queries, $owner, ListLinksQuery::from(search: 'AÇÃO'));
        $byUnaccented = queryRepoListIds($this->queries, $owner, ListLinksQuery::from(search: 'acao'));
        $nullTitleMiss = queryRepoListIds($this->queries, $owner, ListLinksQuery::from(search: 'especial'));

        expect($byTitleOrSlug)->toBe([$titleHit, $slugHit])
            ->and($byAccent)->toBe([$accentHit])
            ->and($byUnaccented)->toBe([])
            ->and($nullTitleMiss)->toBe([$titleHit]);
    });

    it('derives effective status with blocked > expired > inactive > active against one now()', function () {
        $owner = queryRepoInsertUser('query-status@example.com');
        $blocked = queryRepoInsertLink($owner, [
            'slug' => 'blocked1',
            'blocked_at' => '2026-09-01 00:00:00+00',
            'expires_at' => '2026-09-18 11:00:00+00',
            'is_enabled' => false,
            'created_at' => '2026-09-18 10:04:00+00',
        ]);
        $expired = queryRepoInsertLink($owner, [
            'slug' => 'expired1',
            'expires_at' => '2026-09-18 12:00:00+00',
            'created_at' => '2026-09-18 10:03:00+00',
        ]);
        $inactive = queryRepoInsertLink($owner, [
            'slug' => 'inactiv1',
            'is_enabled' => false,
            'created_at' => '2026-09-18 10:02:00+00',
        ]);
        $active = queryRepoInsertLink($owner, [
            'slug' => 'active01',
            'expires_at' => '2026-09-18 12:00:01+00',
            'created_at' => '2026-09-18 10:01:00+00',
        ]);

        $all = $this->queries->listForOwner($owner, ListLinksQuery::from(status: 'all'), null, $this->now);
        $byStatus = fn (string $status) => queryRepoListIds($this->queries, $owner, ListLinksQuery::from(status: $status));

        expect(array_map(fn (LinkSummaryRecord $item) => [$item->id, $item->status->value], $all->items))->toBe([
            [$blocked, 'blocked'],
            [$expired, 'expired'],
            [$inactive, 'inactive'],
            [$active, 'active'],
        ])
            ->and($byStatus('blocked'))->toBe([$blocked])
            ->and($byStatus('expired'))->toBe([$expired])
            ->and($byStatus('inactive'))->toBe([$inactive])
            ->and($byStatus('active'))->toBe([$active]);
    });

    it('does not select destination_url when listing', function () {
        $owner = queryRepoInsertUser('query-cipher@example.com');
        queryRepoInsertLink($owner, [
            'slug' => 'nocipher',
            'title' => 'Privado',
            'created_at' => '2026-09-18 10:00:00+00',
        ]);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $page = $this->queries->listForOwner($owner, ListLinksQuery::from(), null, $this->now);

        $fields = array_map(
            fn (ReflectionProperty $property) => $property->getName(),
            (new ReflectionClass($page->items[0]))->getProperties(),
        );

        expect($page->items)->toHaveCount(1)
            ->and($fields)->not->toContain('destination')
            ->and($fields)->not->toContain('destinationUrl')
            ->and($fields)->not->toContain('destination_url')
            ->and($sql)->not->toBeEmpty();

        foreach ($sql as $statement) {
            expect($statement)->not->toContain('destination_url')
                ->and($statement)->not->toContain(QUERY_REPO_DESTINATION_SENTINEL);
        }
    });
});

describe('EloquentLinkQueryRepository::findForOwner', function () {
    it('returns the owner-scoped row with the current encrypted destination', function () {
        $owner = queryRepoInsertUser('query-detail@example.com');
        $id = queryRepoInsertLink($owner, [
            'slug' => 'detail01',
            'title' => 'Detalhe',
            'created_at' => '2026-09-18 10:00:00+00',
        ]);

        $detail = $this->queries->findForOwner($owner, ShortLinkId::fromString($id));

        expect($detail)->not->toBeNull()
            ->and($detail?->id->value())->toBe($id)
            ->and($detail?->destination->envelope())->toBe(QUERY_REPO_DESTINATION_SENTINEL)
            ->and($detail?->destination->envelope())->not->toContain('https://')
            ->and($detail?->title)->toBe('Detalhe');
    });

    it('returns null for a missing id or a link owned by someone else', function () {
        $owner = queryRepoInsertUser('query-detail-owner@example.com');
        $stranger = queryRepoInsertUser('query-detail-stranger@example.com');
        $foreign = queryRepoInsertLink($stranger, [
            'slug' => 'foreign1',
            'created_at' => '2026-09-18 10:00:00+00',
        ]);
        $missing = '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0fff';

        expect($this->queries->findForOwner($owner, ShortLinkId::fromString($foreign)))->toBeNull()
            ->and($this->queries->findForOwner($owner, ShortLinkId::fromString($missing)))->toBeNull();
    });
});
