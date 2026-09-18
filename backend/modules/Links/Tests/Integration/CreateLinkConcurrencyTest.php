<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Http\Responses\LinkErrorResponseFactory;
use Modules\Links\UseCases\CreateLink;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Concurrent CreateLink of case-equivalent aliases (LNC-15)
|--------------------------------------------------------------------------
|
| Why this file does NOT rely on a transactional RefreshDatabase for the race:
| RefreshDatabase wraps the default connection in a single transaction, so a
| "commit" on it is only a SAVEPOINT release — a second connection would never
| see the row and the primary-key collision under test could not happen. The
| race is therefore run on two dedicated real connections (pgsql_winner /
| pgsql_loser) that commit for real, and the rows they leave behind are
| removed explicitly in afterEach. RefreshDatabase is still applied so the
| schema exists and the default connection stays clean.
|
| Discrimination sensor: if CreateLink stopped mapping a unique-constraint
| collision to SlugUnavailable the "exactly one 201 and one 409" test fails;
| if the loser path deleted or rewrote the winner reservation the "loser does
| not alter" test fails; if the 409 body or exception gained owner data the
| "does not reveal winner owner" test fails; if the slug PK were dropped the
| "exactly one row" test fails.
|
*/

uses(TestCase::class, RefreshDatabase::class);

const CREATE_LINK_CONCURRENCY_SLUG = 'racefoo';

beforeEach(function () {
    $base = config('database.connections.pgsql');

    DatabaseSafetyGuard::assertIsolated((string) $base['database']);

    config([
        'database.connections.pgsql_winner' => $base,
        'database.connections.pgsql_loser' => $base,
    ]);

    $this->winner = DB::connection('pgsql_winner');
    $this->loser = DB::connection('pgsql_loser');
});

afterEach(function () {
    // Winner/loser connections commit real rows; scrub them so the next test
    // and the next run start clean. Disconnect before purge so RefreshDatabase
    // teardown on the default connection cannot deadlock against an open
    // secondary session still holding AccessShareLock.
    scrubCreateLinkConcurrencyRows($this->winner, CREATE_LINK_CONCURRENCY_SLUG);

    DB::setDefaultConnection('pgsql');
    $this->winner->disconnect();
    $this->loser->disconnect();
    DB::purge('pgsql_winner');
    DB::purge('pgsql_loser');
});

function scrubCreateLinkConcurrencyRows(ConnectionInterface $conn, string $slug): void
{
    $linkIds = $conn->table('short_links')->where('slug', $slug)->pluck('id')->all();

    if ($linkIds !== []) {
        $conn->table('link_destination_versions')->whereIn('short_link_id', $linkIds)->delete();
    }

    $conn->table('short_links')->where('slug', $slug)->delete();
    $conn->table('slug_reservations')->where('slug', $slug)->delete();
    $conn->table('users')->where('email', 'like', 'create-link-concurrency-%@example.com')->delete();
}

function seedCreateLinkConcurrencyOwner(ConnectionInterface $conn): string
{
    $id = (string) Str::uuid7();

    $conn->table('users')->insert([
        'id' => $id,
        'name' => 'Create Link Concurrency User',
        'email' => 'create-link-concurrency-'.$id.'@example.com',
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Run CreateLink against a dedicated connection and map the outcome to the
 * HTTP status the controller would return (201 created / 409 conflict).
 */
function createLinkOnConnection(string $connection, string $ownerId, string $alias): int
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        app(CreateLink::class)->execute(
            UserId::fromString($ownerId),
            new CreateLinkInput(
                destinationUrl: 'https://example.com/concurrency/'.$alias,
                customAlias: $alias,
                title: null,
                expiresAt: null,
            ),
        );

        return 201;
    } catch (SlugUnavailable $exception) {
        return app(LinkErrorResponseFactory::class)
            ->fromSlugUnavailable($exception)
            ->getStatusCode();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

describe('concurrent CreateLink of case-equivalent aliases', function () {
    it('yields exactly one 201 and one 409 between two concurrent equivalent-alias creations', function () {
        $winnerOwner = seedCreateLinkConcurrencyOwner($this->winner);
        $loserOwner = seedCreateLinkConcurrencyOwner($this->winner);

        $statuses = [
            createLinkOnConnection('pgsql_winner', $winnerOwner, 'RaceFoo'),
            createLinkOnConnection('pgsql_loser', $loserOwner, 'racefoo'),
        ];

        sort($statuses);

        expect($statuses)->toBe([201, 409]);
    });

    it('leaves a single slug_reservations row and a single short_link for the slug', function () {
        $winnerOwner = seedCreateLinkConcurrencyOwner($this->winner);
        $loserOwner = seedCreateLinkConcurrencyOwner($this->winner);

        createLinkOnConnection('pgsql_winner', $winnerOwner, 'RaceFoo');
        createLinkOnConnection('pgsql_loser', $loserOwner, 'racefoo');

        $reservationRows = (int) $this->winner->table('slug_reservations')
            ->where('slug', CREATE_LINK_CONCURRENCY_SLUG)
            ->count();
        $linkRows = (int) $this->winner->table('short_links')
            ->where('slug', CREATE_LINK_CONCURRENCY_SLUG)
            ->count();
        $versionRows = (int) $this->winner->table('link_destination_versions as v')
            ->join('short_links as l', 'l.id', '=', 'v.short_link_id')
            ->where('l.slug', CREATE_LINK_CONCURRENCY_SLUG)
            ->count();

        expect($reservationRows)->toBe(1)
            ->and($linkRows)->toBe(1)
            ->and($versionRows)->toBe(1);
    });

    it('does not let the loser alter or remove the winner reservation', function () {
        $winnerOwner = seedCreateLinkConcurrencyOwner($this->winner);
        $loserOwner = seedCreateLinkConcurrencyOwner($this->winner);

        expect(createLinkOnConnection('pgsql_winner', $winnerOwner, 'RaceFoo'))->toBe(201);

        $before = $this->winner->table('slug_reservations')
            ->where('slug', CREATE_LINK_CONCURRENCY_SLUG)
            ->first();

        expect($before)->not->toBeNull();

        expect(createLinkOnConnection('pgsql_loser', $loserOwner, 'racefoo'))->toBe(409);

        $after = $this->winner->table('slug_reservations')
            ->where('slug', CREATE_LINK_CONCURRENCY_SLUG)
            ->first();

        expect($after)->not->toBeNull()
            ->and((string) $after->reserved_at)->toBe((string) $before->reserved_at)
            ->and((int) $this->winner->table('slug_reservations')->where('slug', CREATE_LINK_CONCURRENCY_SLUG)->count())->toBe(1)
            ->and((int) $this->winner->table('short_links')->where('slug', CREATE_LINK_CONCURRENCY_SLUG)->count())->toBe(1)
            ->and(
                (string) $this->winner->table('short_links')->where('slug', CREATE_LINK_CONCURRENCY_SLUG)->value('user_id')
            )->toBe($winnerOwner);
    });

    it('does not reveal the winner owner in the loser failure', function () {
        $winnerOwner = seedCreateLinkConcurrencyOwner($this->winner);
        $loserOwner = seedCreateLinkConcurrencyOwner($this->winner);

        createLinkOnConnection('pgsql_winner', $winnerOwner, 'RaceFoo');

        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_loser');

        $caught = null;

        try {
            app(CreateLink::class)->execute(
                UserId::fromString($loserOwner),
                new CreateLinkInput(
                    destinationUrl: 'https://example.com/concurrency/racefoo',
                    customAlias: 'racefoo',
                    title: null,
                    expiresAt: null,
                ),
            );
        } catch (SlugUnavailable $exception) {
            $caught = $exception;
        } finally {
            DB::setDefaultConnection($previous);
        }

        expect($caught)->toBeInstanceOf(SlugUnavailable::class);

        $response = app(LinkErrorResponseFactory::class)->fromSlugUnavailable($caught);
        $payload = json_encode($response->getData(true), JSON_THROW_ON_ERROR);

        expect($response->getStatusCode())->toBe(409)
            ->and($caught->getMessage())->not->toContain($winnerOwner)
            ->and($caught->getMessage())->not->toContain('RaceFoo')
            ->and($caught->getMessage())->not->toContain('racefoo')
            ->and($payload)->not->toContain($winnerOwner)
            ->and($payload)->not->toContain('RaceFoo')
            ->and($payload)->not->toContain('racefoo')
            ->and($payload)->not->toContain('create-link-concurrency-');
    });
});
