<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const LINK_QUERY_TITLE_TRGM_INDEX = 'short_links_title_trgm_idx';
const LINK_QUERY_OWNER_CREATED_ID_INDEX = 'short_links_owner_created_id_idx';
const LINK_QUERY_OWNER_SLUG_PREFIX_INDEX = 'short_links_owner_slug_prefix_idx';

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

/**
 * @return array<string, mixed>
 */
function explainPlan(string $sql): array
{
    $row = DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql);
    $payload = json_decode((string) $row->{'QUERY PLAN'}, true);

    expect($payload)->toBeArray()
        ->and($payload[0]['Plan'] ?? null)->toBeArray();

    return $payload[0]['Plan'];
}

/**
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function indexNamesFromPlan(array $node): array
{
    $names = [];

    if (isset($node['Index Name']) && is_string($node['Index Name'])) {
        $names[] = $node['Index Name'];
    }

    foreach ($node['Plans'] ?? [] as $child) {
        if (is_array($child)) {
            $names = [...$names, ...indexNamesFromPlan($child)];
        }
    }

    return $names;
}

/**
 * @return array{user_id: string, slug: string}
 */
function seedQueryIndexedLink(string $email, string $slug, ?string $title, string $createdAt): array
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Query Index User',
        'email' => $email,
        'password' => 'hash',
        'status' => 'pending_verification',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    SlugReservationModel::create([
        'slug' => $slug,
        'reserved_at' => now(),
    ]);

    DB::table('short_links')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'slug' => $slug,
        'slug_source' => 'automatic',
        'title' => $title,
        'is_enabled' => true,
        'version' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return ['user_id' => $userId, 'slug' => $slug];
}

describe('link query indexes', function () {
    it('installs pg_trgm and the title, owner keyset, and slug prefix indexes', function () {
        $extension = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'");
        $indexes = collect(DB::select(
            "SELECT indexname, indexdef
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = 'short_links'"
        ))->keyBy('indexname');

        expect($extension?->extname)->toBe('pg_trgm')
            ->and($indexes->has(LINK_QUERY_TITLE_TRGM_INDEX))->toBeTrue()
            ->and($indexes->get(LINK_QUERY_TITLE_TRGM_INDEX)->indexdef)
            ->toContain('USING gin')
            ->toContain('lower')
            ->toContain('gin_trgm_ops')
            ->and($indexes->has(LINK_QUERY_OWNER_CREATED_ID_INDEX))->toBeTrue()
            ->and($indexes->get(LINK_QUERY_OWNER_CREATED_ID_INDEX)->indexdef)
            ->toContain('user_id')
            ->toContain('created_at DESC')
            ->toContain('id DESC')
            ->and($indexes->has(LINK_QUERY_OWNER_SLUG_PREFIX_INDEX))->toBeTrue()
            ->and($indexes->get(LINK_QUERY_OWNER_SLUG_PREFIX_INDEX)->indexdef)
            ->toContain('user_id')
            ->toContain('slug')
            ->toContain('varchar_pattern_ops');
    });

    it('uses the owner keyset index for created_at desc and id desc ordering', function () {
        $link = seedQueryIndexedLink(
            'query-order@example.com',
            'orderidx',
            'Campanha principal',
            '2026-09-18 12:00:00+00',
        );

        DB::statement('SET LOCAL enable_seqscan = off');

        $plan = explainPlan(sprintf(
            "SELECT id FROM short_links WHERE user_id = '%s' ORDER BY created_at DESC, id DESC LIMIT 21",
            $link['user_id'],
        ));

        expect(indexNamesFromPlan($plan))->toContain(LINK_QUERY_OWNER_CREATED_ID_INDEX);
    });

    it('uses the title trigram index for case-insensitive substring search', function () {
        $link = seedQueryIndexedLink(
            'query-title@example.com',
            'titleidx',
            'Campanha de lançamento',
            '2026-09-18 12:00:00+00',
        );

        DB::statement('SET LOCAL enable_seqscan = off');

        $plan = explainPlan(
            "SELECT id FROM short_links WHERE lower(title) LIKE '%campanha%'",
        );

        expect(indexNamesFromPlan($plan))->toContain(LINK_QUERY_TITLE_TRGM_INDEX);
    });

    it('uses the slug prefix index for canonical lowercase prefix search', function () {
        $link = seedQueryIndexedLink(
            'query-slug@example.com',
            'campanha',
            null,
            '2026-09-18 12:00:00+00',
        );

        DB::statement('SET LOCAL enable_seqscan = off');

        $plan = explainPlan(
            "SELECT id FROM short_links WHERE slug LIKE 'camp%'",
        );

        expect(indexNamesFromPlan($plan))->toContain(LINK_QUERY_OWNER_SLUG_PREFIX_INDEX);
    });

    it('is reversible without dropping pg_trgm', function () {
        $migration = require base_path('database/migrations/2026_09_18_122821_add_link_query_indexes.php');

        $migration->down();

        $indexesAfterDown = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'short_links'"
        ))->pluck('indexname');
        $extensionAfterDown = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'");

        expect($indexesAfterDown)->not->toContain(LINK_QUERY_TITLE_TRGM_INDEX)
            ->and($indexesAfterDown)->not->toContain(LINK_QUERY_OWNER_CREATED_ID_INDEX)
            ->and($indexesAfterDown)->not->toContain(LINK_QUERY_OWNER_SLUG_PREFIX_INDEX)
            ->and($extensionAfterDown?->extname)->toBe('pg_trgm');

        $migration->up();

        $indexesAfterUp = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'short_links'"
        ))->pluck('indexname');

        expect($indexesAfterUp)->toContain(LINK_QUERY_TITLE_TRGM_INDEX)
            ->and($indexesAfterUp)->toContain(LINK_QUERY_OWNER_CREATED_ID_INDEX)
            ->and($indexesAfterUp)->toContain(LINK_QUERY_OWNER_SLUG_PREFIX_INDEX);
    });
});
