<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\IdempotencySnapshotCipher;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\TransactionManager;
use Modules\Links\Domain\Services\CanonicalCreateLinkCommand;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Domain\ValueObjects\IdempotencyKey;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\DTOs\Output\EncryptedIdempotencySnapshot;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;
use Modules\Links\Exceptions\IdempotencyKeyReused;
use Modules\Links\Infrastructure\Http\Responses\LinkCreationSnapshotFactory;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\LinkDestinationVersionMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\ShortLinkMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\UseCases\CreateIdempotentLink;
use Modules\Links\UseCases\CreateLink;
use Modules\Links\UseCases\ReserveSlug;
use Modules\Links\UseCases\SealDestinationUrl;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

final class IdempotentCreateSequencedSlugSource implements RandomSlugSource
{
    public int $calls = 0;

    /** @param  list<string>  $sequence */
    public function __construct(private array $sequence) {}

    public function candidate(int $length, string $alphabet): string
    {
        $value = $this->sequence[$this->calls] ?? throw new RuntimeException('forced snapshot encrypt failure');
        $this->calls++;

        return $value;
    }
}

final class ThrowingIdempotencySnapshotCipher implements IdempotencySnapshotCipher
{
    public function __construct(private IdempotencySnapshotCipher $inner) {}

    public function encrypt(IdempotencyResponseSnapshot $snapshot): EncryptedIdempotencySnapshot
    {
        throw new RuntimeException('forced snapshot encrypt failure');
    }

    public function decrypt(EncryptedIdempotencySnapshot $encrypted): IdempotencyResponseSnapshot
    {
        return $this->inner->decrypt($encrypted);
    }
}

function idempotentCreateOwner(string $suffix = 'idem'): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Idempotent Create User',
        'email' => Str::uuid7().'@'.$suffix.'.example.com',
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return UserId::fromString($userId);
}

function makeCreateLinkForIdempotency(?RandomSlugSource $source = null): CreateLink
{
    $hosts = app(PublicHostClassifier::class);
    $policy = new SlugPolicy(new ConfigReservedSlugs);
    $source ??= new IdempotentCreateSequencedSlugSource(['idem0001']);
    $generator = new SlugGenerator($source, $policy, 8, 5);
    $reserve = new ReserveSlug($policy, $generator, new EloquentSlugReservationRepository, 5);

    return new CreateLink(
        sealDestinationUrl: new SealDestinationUrl($hosts, app(DestinationCipher::class)),
        hosts: $hosts,
        reserveSlug: $reserve,
        shortLinks: new EloquentShortLinkRepository(new Uuid7ShortLinkIdGenerator, new ShortLinkMapper),
        destinationVersions: new EloquentDestinationVersionRepository(
            new Uuid7LinkDestinationVersionIdGenerator,
            new LinkDestinationVersionMapper,
        ),
        effectiveStatus: new EffectiveStatus,
        transactions: app(TransactionManager::class),
    );
}

function makeCreateIdempotentLink(
    CreateLink $createLink,
    ?IdempotencySnapshotCipher $cipher = null,
): CreateIdempotentLink {
    return new CreateIdempotentLink(
        transactions: app(TransactionManager::class),
        createLink: $createLink,
        idempotencyKeys: app(IdempotencyKeyRepository::class),
        canonical: app(CanonicalCreateLinkCommand::class),
        snapshots: $cipher ?? app(IdempotencySnapshotCipher::class),
        snapshotFactory: new LinkCreationSnapshotFactory(app(LinkETag::class)),
    );
}

function sampleCreateLinkInput(string $destination = 'https://example.com/idem'): CreateLinkInput
{
    return new CreateLinkInput(
        destinationUrl: $destination,
        customAlias: null,
        title: 'Idempotent',
        expiresAt: null,
    );
}

function seedIdemConcurrencyOwner(ConnectionInterface $conn): UserId
{
    $userId = (string) Str::uuid7();

    $conn->table('users')->insert([
        'id' => $userId,
        'name' => 'Idempotency Concurrency User',
        'email' => 'idem-concurrency-'.$userId.'@idem-concurrency.example.com',
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return UserId::fromString($userId);
}

function scrubIdemConcurrencyArtifacts(ConnectionInterface $conn): void
{
    $conn->table('idempotency_keys')->delete();
    $conn->table('link_destination_versions')->delete();
    $conn->table('short_links')->delete();
    $conn->table('slug_reservations')->delete();
    $conn->table('users')->where('email', 'like', '%@idem-concurrency.example.com')->delete();
}

describe('CreateIdempotentLink', function () {
    it('creates a link and stores an encrypted snapshot on first use of a key', function () {
        $owner = idempotentCreateOwner('first');
        $source = new IdempotentCreateSequencedSlugSource(['first001']);
        $useCase = makeCreateIdempotentLink(makeCreateLinkForIdempotency($source));
        $key = IdempotencyKey::fromString('idem-key-first-use-01');

        $result = $useCase->execute($owner, sampleCreateLinkInput('https://example.com/first'), $key);

        expect($result->replayed)->toBeFalse()
            ->and($result->created)->not->toBeNull()
            ->and($result->created->slug)->toBe('first001')
            ->and($result->snapshot->status)->toBe(201)
            ->and($result->snapshot->headers['Location'])->toBe('/api/v1/links/'.$result->created->id)
            ->and($result->snapshot->headers)->toHaveKeys(['Location', 'ETag', 'Cache-Control'])
            ->and(DB::table('short_links')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->whereNotNull('response_snapshot')->count())->toBe(1);

        $row = DB::table('idempotency_keys')->first();
        expect($row)->not->toBeNull();

        $createdAt = new DateTimeImmutable((string) $row->created_at);
        $expiresAt = new DateTimeImmutable((string) $row->expires_at);

        expect($expiresAt->getTimestamp() - $createdAt->getTimestamp())->toBe(24 * 60 * 60);
    });

    it('replays the same snapshot without calling CreateLink again', function () {
        $owner = idempotentCreateOwner('replay');
        $source = new IdempotentCreateSequencedSlugSource(['replay01']);
        $useCase = makeCreateIdempotentLink(makeCreateLinkForIdempotency($source));
        $key = IdempotencyKey::fromString('idem-key-replay-same-1');
        $input = sampleCreateLinkInput('https://example.com/replay');

        $first = $useCase->execute($owner, $input, $key);
        $callsAfterCreate = $source->calls;

        $second = $useCase->execute($owner, $input, $key);

        expect($second->replayed)->toBeTrue()
            ->and($second->created)->toBeNull()
            ->and($second->snapshot->status)->toBe($first->snapshot->status)
            ->and($second->snapshot->body)->toBe($first->snapshot->body)
            ->and($second->snapshot->headers)->toBe($first->snapshot->headers)
            ->and($source->calls)->toBe($callsAfterCreate)
            ->and(DB::table('short_links')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1);
    });

    it('rejects the same key with a different canonical command', function () {
        $owner = idempotentCreateOwner('conflict');
        $useCase = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['confl001'])),
        );
        $key = IdempotencyKey::fromString('idem-key-conflict-01');

        $useCase->execute($owner, sampleCreateLinkInput('https://example.com/a'), $key);

        $useCase->execute($owner, sampleCreateLinkInput('https://example.com/b'), $key);
    })->throws(IdempotencyKeyReused::class);

    it('rolls back key reservation and link when snapshot encryption fails', function () {
        $owner = idempotentCreateOwner('rollback');
        $useCase = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['rollb001'])),
            new ThrowingIdempotencySnapshotCipher(app(IdempotencySnapshotCipher::class)),
        );
        $key = IdempotencyKey::fromString('idem-key-rollback-01');

        try {
            $useCase->execute($owner, sampleCreateLinkInput('https://example.com/rollback'), $key);
            expect(false)->toBeTrue();
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('forced snapshot encrypt failure');
        }

        expect(DB::table('short_links')->count())->toBe(0)
            ->and(DB::table('link_destination_versions')->count())->toBe(0)
            ->and(DB::table('slug_reservations')->count())->toBe(0)
            ->and(DB::table('idempotency_keys')->count())->toBe(0);
    });

    it('allows reuse of an expired key for a new creation', function () {
        $owner = idempotentCreateOwner('expired');
        $canonical = app(CanonicalCreateLinkCommand::class);
        $key = IdempotencyKey::fromString('idem-key-expired-01');
        $input = sampleCreateLinkInput('https://example.com/expired-new');
        $keyHash = $canonical->hashKey($owner, $key);
        $fingerprint = $canonical->fingerprint($owner, $input);

        DB::table('idempotency_keys')->insert([
            'user_id' => $owner->value(),
            'key_hash' => $keyHash,
            'request_fingerprint' => $fingerprint,
            'response_snapshot' => null,
            'key_id' => null,
            'created_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $useCase = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['expir001'])),
        );

        $result = $useCase->execute($owner, $input, $key);

        expect($result->replayed)->toBeFalse()
            ->and($result->created?->slug)->toBe('expir001')
            ->and(DB::table('short_links')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->count())->toBe(1)
            ->and(DB::table('idempotency_keys')->whereNotNull('response_snapshot')->count())->toBe(1);
    });

    it('isolates the same raw key across different owners', function () {
        $ownerA = idempotentCreateOwner('iso-a');
        $ownerB = idempotentCreateOwner('iso-b');
        $key = IdempotencyKey::fromString('idem-key-isolation-1');

        $resultA = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['isola001'])),
        )->execute($ownerA, sampleCreateLinkInput('https://example.com/a'), $key);

        $resultB = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['isolb001'])),
        )->execute($ownerB, sampleCreateLinkInput('https://example.com/b'), $key);

        expect($resultA->replayed)->toBeFalse()
            ->and($resultB->replayed)->toBeFalse()
            ->and($resultA->created?->id)->not->toBe($resultB->created?->id)
            ->and(DB::table('short_links')->count())->toBe(2)
            ->and(DB::table('idempotency_keys')->count())->toBe(2);
    });
});

describe('CreateIdempotentLink concurrency across two connections', function () {
    beforeEach(function () {
        $base = config('database.connections.pgsql');

        config([
            'database.connections.pgsql_idem_a' => $base,
            'database.connections.pgsql_idem_b' => $base,
        ]);

        $this->connA = DB::connection('pgsql_idem_a');
        $this->connB = DB::connection('pgsql_idem_b');
    });

    afterEach(function () {
        scrubIdemConcurrencyArtifacts($this->connA);

        DB::setDefaultConnection('pgsql');
        $this->connA->disconnect();
        $this->connB->disconnect();
        DB::purge('pgsql_idem_a');
        DB::purge('pgsql_idem_b');
    });

    it('creates one link and replays the second caller on another connection', function () {
        $owner = seedIdemConcurrencyOwner($this->connA);
        $key = IdempotencyKey::fromString('idem-key-concurrency-01');
        $input = sampleCreateLinkInput('https://example.com/concurrency');
        $source = new IdempotentCreateSequencedSlugSource(['conc0001', 'conc0002']);
        $useCase = makeCreateIdempotentLink(makeCreateLinkForIdempotency($source));

        $previous = DB::getDefaultConnection();

        DB::setDefaultConnection('pgsql_idem_a');
        $first = $useCase->execute($owner, $input, $key);

        DB::setDefaultConnection('pgsql_idem_b');
        $second = $useCase->execute($owner, $input, $key);

        DB::setDefaultConnection($previous);

        expect($first->replayed)->toBeFalse()
            ->and($second->replayed)->toBeTrue()
            ->and($second->snapshot->body)->toBe($first->snapshot->body)
            ->and($second->snapshot->headers)->toBe($first->snapshot->headers)
            ->and($this->connA->table('short_links')->count())->toBe(1)
            ->and($this->connA->table('idempotency_keys')->count())->toBe(1)
            ->and($source->calls)->toBe(1);
    });

    it('clears author residue after rollback and allows a later create with the same key', function () {
        $owner = seedIdemConcurrencyOwner($this->connA);
        $key = IdempotencyKey::fromString('idem-key-concurrency-rb');
        $input = sampleCreateLinkInput('https://example.com/concurrency-rb');

        $failing = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['fail0001'])),
            new ThrowingIdempotencySnapshotCipher(app(IdempotencySnapshotCipher::class)),
        );

        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_idem_a');

        try {
            $failing->execute($owner, $input, $key);
            expect(false)->toBeTrue();
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('forced snapshot encrypt failure');
        }

        expect($this->connA->table('short_links')->count())->toBe(0)
            ->and($this->connA->table('link_destination_versions')->count())->toBe(0)
            ->and($this->connA->table('slug_reservations')->count())->toBe(0)
            ->and($this->connA->table('idempotency_keys')->count())->toBe(0);

        DB::setDefaultConnection('pgsql_idem_b');

        $succeeding = makeCreateIdempotentLink(
            makeCreateLinkForIdempotency(new IdempotentCreateSequencedSlugSource(['ok000001'])),
        );
        $result = $succeeding->execute($owner, $input, $key);

        DB::setDefaultConnection($previous);

        expect($result->replayed)->toBeFalse()
            ->and($this->connB->table('short_links')->count())->toBe(1)
            ->and($this->connB->table('idempotency_keys')->count())->toBe(1);
    });
});
