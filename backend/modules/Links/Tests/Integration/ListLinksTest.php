<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\CursorCodec;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\Infrastructure\Crypto\ConfigCursorSigningKey;
use Modules\Links\Infrastructure\Pagination\HmacCursorCodec;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentLinkQueryRepository;
use Modules\Links\UseCases\ListLinks;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated(
        (string) config('database.connections.pgsql.database'),
    );
});

final class ListLinksIntegrationClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}

function listLinksInsertUser(string $email): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'List Links User',
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
 *     created_at: string
 * }  $attrs
 */
function listLinksInsertLink(UserId $owner, array $attrs): string
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
        'destination_url' => 'sealed-must-not-leak-into-list',
        'key_id' => 'testing-key-1',
        'valid_from' => $createdAt,
        'valid_to' => null,
    ]);

    return $id;
}

function makeListLinksUseCase(Clock $clock): ListLinks
{
    return new ListLinks(
        queries: new EloquentLinkQueryRepository,
        cursors: new HmacCursorCodec(new ConfigCursorSigningKey('integration-cursor-hmac-key')),
        clock: $clock,
    );
}

describe('ListLinks integration', function () {
    it('returns an empty page with next_cursor null and the effective per_page', function () {
        $owner = listLinksInsertUser('list-empty@example.com');
        $clock = new ListLinksIntegrationClock(new DateTimeImmutable('2026-09-18T12:00:00Z'));

        $page = makeListLinksUseCase($clock)->execute(
            $owner,
            ListLinksQuery::from(perPage: 7),
            null,
        );

        expect($page->items)->toBe([])
            ->and($page->nextCursor)->toBeNull()
            ->and($page->perPage)->toBe(7);
    });

    it('emits next_cursor only when another page exists and the following page does not repeat items', function () {
        $owner = listLinksInsertUser('list-pages@example.com');
        $newer = listLinksInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f31',
            'slug' => 'pageaaa1',
            'created_at' => '2026-09-18T11:00:00Z',
        ]);
        $middle = listLinksInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f30',
            'slug' => 'pageaaa2',
            'created_at' => '2026-09-18T10:00:00Z',
        ]);
        $older = listLinksInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f2f',
            'slug' => 'pageaaa3',
            'created_at' => '2026-09-18T09:00:00Z',
        ]);
        $clock = new ListLinksIntegrationClock(new DateTimeImmutable('2026-09-18T12:00:00Z'));
        $useCase = makeListLinksUseCase($clock);
        $query = ListLinksQuery::from(perPage: 2);

        $first = $useCase->execute($owner, $query, null);
        $second = $useCase->execute($owner, $query, $first->nextCursor);

        expect(array_map(fn ($item) => $item->id, $first->items))->toBe([$newer, $middle])
            ->and($first->nextCursor)->not->toBeNull()
            ->and($first->perPage)->toBe(2)
            ->and(array_map(fn ($item) => $item->id, $second->items))->toBe([$older])
            ->and($second->nextCursor)->toBeNull()
            ->and($second->items[0]->id)->not->toBe($newer)
            ->and($second->items[0]->id)->not->toBe($middle);
    });

    it('recomputes effective status with each request now while the cursor only preserves order', function () {
        $owner = listLinksInsertUser('list-expiry@example.com');
        $expiring = listLinksInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f41',
            'slug' => 'expiring1',
            'expires_at' => '2026-09-18T12:00:00Z',
            'created_at' => '2026-09-18T11:00:00Z',
        ]);
        $older = listLinksInsertLink($owner, [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f40',
            'slug' => 'expiring2',
            'created_at' => '2026-09-18T10:00:00Z',
        ]);
        $clock = new ListLinksIntegrationClock(new DateTimeImmutable('2026-09-18T11:59:59Z'));
        $useCase = makeListLinksUseCase($clock);
        $query = ListLinksQuery::from(perPage: 1);

        $first = $useCase->execute($owner, $query, null);
        $clock->travelTo(new DateTimeImmutable('2026-09-18T12:00:00Z'));
        $second = $useCase->execute($owner, $query, $first->nextCursor);

        expect($first->items[0]->id)->toBe($expiring)
            ->and($first->items[0]->status)->toBe(LinkStatus::Active)
            ->and($second->items[0]->id)->toBe($older)
            ->and($second->nextCursor)->toBeNull();

        $requery = $useCase->execute($owner, ListLinksQuery::from(), null);

        expect($requery->items[0]->id)->toBe($expiring)
            ->and($requery->items[0]->status)->toBe(LinkStatus::Expired)
            ->and($requery->items[1]->id)->toBe($older)
            ->and($requery->items[1]->status)->toBe(LinkStatus::Active);
    });

    it('does not include destination_url on summary records', function () {
        $owner = listLinksInsertUser('list-redact@example.com');
        listLinksInsertLink($owner, [
            'slug' => 'noleak01',
            'created_at' => '2026-09-18T11:00:00Z',
        ]);

        $page = makeListLinksUseCase(new ListLinksIntegrationClock(new DateTimeImmutable('2026-09-18T12:00:00Z')))
            ->execute($owner, ListLinksQuery::from(), null);

        expect($page->items)->toHaveCount(1)
            ->and(get_object_vars($page->items[0]))->not->toHaveKey('destinationUrl')
            ->and(json_encode($page->items[0]))->not->toContain('sealed-must-not-leak-into-list');
    });

    it('never invokes DestinationCipher while listing', function () {
        $constructor = (new ReflectionClass(ListLinks::class))->getConstructor();
        $types = array_map(
            static fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $constructor?->getParameters() ?? [],
        );

        expect($types)->not->toContain(DestinationCipher::class)
            ->and($types)->toContain(CursorCodec::class);
    });
});
