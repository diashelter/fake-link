<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Contracts\Services\TransactionManager;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\LinkDestinationVersionId;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\DTOs\Output\PersistedShortLink;
use Modules\Links\Exceptions\LinksDomainException;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\LinkDestinationVersionMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\ShortLinkMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\UseCases\CreateLink;
use Modules\Links\UseCases\ReserveSlug;
use Modules\Links\UseCases\SealDestinationUrl;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

final class CreateLinkSequencedSlugSource implements RandomSlugSource
{
    public int $calls = 0;

    /** @param  list<string>  $sequence */
    public function __construct(private array $sequence) {}

    public function candidate(int $length, string $alphabet): string
    {
        $value = $this->sequence[$this->calls] ?? throw new RuntimeException('scripted sequence exhausted');
        $this->calls++;

        return $value;
    }
}

final class RecordingDestinationCipher implements DestinationCipher
{
    public int $encryptCalls = 0;

    public function __construct(private DestinationCipher $inner) {}

    public function encrypt(DestinationUrl $url): EncryptedDestination
    {
        $this->encryptCalls++;

        return $this->inner->encrypt($url);
    }

    public function decrypt(EncryptedDestination $encrypted): DestinationUrl
    {
        return $this->inner->decrypt($encrypted);
    }
}

function createLinkOwner(): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Create Link User',
        'email' => Str::uuid7().'@example.com',
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return UserId::fromString($userId);
}

function makeCreateLink(
    ?RandomSlugSource $source = null,
    int $maxCollisionAttempts = 5,
    ?DestinationCipher $cipher = null,
): CreateLink {
    $hosts = app(PublicHostClassifier::class);
    $cipher ??= app(DestinationCipher::class);
    $policy = new SlugPolicy(new ConfigReservedSlugs);
    $source ??= new CreateLinkSequencedSlugSource(['abcd1234']);
    $generator = new SlugGenerator($source, $policy, 8, 5);
    $reserve = new ReserveSlug($policy, $generator, new EloquentSlugReservationRepository, $maxCollisionAttempts);

    return new CreateLink(
        sealDestinationUrl: new SealDestinationUrl($hosts, $cipher),
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

/**
 * @return array{reservations: int, links: int, versions: int}
 */
function tableCounts(): array
{
    return [
        'reservations' => DB::table('slug_reservations')->count(),
        'links' => DB::table('short_links')->count(),
        'versions' => DB::table('link_destination_versions')->count(),
    ];
}

describe('CreateLink happy path', function () {
    it('creates exactly one row in each of the three tables for an automatic slug', function () {
        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource(['auto1234']));

        $result = $useCase->execute($owner, new CreateLinkInput(
            destinationUrl: 'https://example.com/path',
            customAlias: null,
            title: null,
            expiresAt: null,
        ));

        expect(tableCounts())->toBe(['reservations' => 1, 'links' => 1, 'versions' => 1])
            ->and($result->slug)->toBe('auto1234')
            ->and($result->slugSource)->toBe(SlugSource::Automatic)
            ->and($result->isEnabled)->toBeTrue()
            ->and($result->blockedAt)->toBeNull()
            ->and($result->version)->toBe(1)
            ->and($result->status)->toBe(LinkStatus::Active)
            ->and($result->title)->toBeNull()
            ->and($result->expiresAt)->toBeNull();

        $link = ShortLinkModel::query()->findOrFail($result->id);
        expect($link->user_id)->toBe($owner->value())
            ->and($link->slug_source)->toBe('automatic');
    });

    it('creates a custom alias link with slug_source custom', function () {
        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource([]));

        $result = $useCase->execute($owner, new CreateLinkInput(
            destinationUrl: 'https://example.com/custom',
            customAlias: 'My-Alias',
            title: 'Launch',
            expiresAt: new DateTimeImmutable('2030-01-01T00:00:00Z'),
        ));

        expect($result->slug)->toBe('my-alias')
            ->and($result->slugSource)->toBe(SlugSource::Custom)
            ->and($result->title)->toBe('Launch')
            ->and($result->expiresAt)->not->toBeNull()
            ->and(tableCounts())->toBe(['reservations' => 1, 'links' => 1, 'versions' => 1]);
    });

    it('returns the normalized destination URL in the DTO while persisting ciphertext only', function () {
        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource(['norm1234']));
        $raw = 'https://EXAMPLE.com/Path';

        $result = $useCase->execute($owner, new CreateLinkInput(
            destinationUrl: $raw,
            customAlias: null,
            title: null,
            expiresAt: null,
        ));

        $stored = DB::table('link_destination_versions')->value('destination_url');

        expect($result->destinationUrl)->not->toBe($raw)
            ->and($result->destinationUrl)->toContain('example.com')
            ->and($stored)->not->toContain('EXAMPLE.com')
            ->and($stored)->not->toContain($raw);
    });
});

describe('CreateLink transactional integrity', function () {
    it('rolls back reservation and link when destination version insert fails', function () {
        $owner = createLinkOwner();
        $hosts = app(PublicHostClassifier::class);
        $cipher = app(DestinationCipher::class);
        $policy = new SlugPolicy(new ConfigReservedSlugs);
        $source = new CreateLinkSequencedSlugSource(['failver1']);
        $generator = new SlugGenerator($source, $policy, 8, 5);
        $reserve = new ReserveSlug($policy, $generator, new EloquentSlugReservationRepository, 5);
        $shortLinks = new EloquentShortLinkRepository(new Uuid7ShortLinkIdGenerator, new ShortLinkMapper);
        $innerVersions = new EloquentDestinationVersionRepository(
            new Uuid7LinkDestinationVersionIdGenerator,
            new LinkDestinationVersionMapper,
        );

        $versions = new class($innerVersions) implements DestinationVersionRepository
        {
            public function __construct(private DestinationVersionRepository $inner) {}

            public function openFirstVersion(
                ShortLinkId $shortLinkId,
                EncryptedDestination $encrypted,
                DateTimeImmutable $validFrom,
            ): LinkDestinationVersionId {
                $this->inner->openFirstVersion($shortLinkId, $encrypted, $validFrom);

                return $this->inner->openFirstVersion($shortLinkId, $encrypted, $validFrom->modify('+1 second'));
            }
        };

        $useCase = new CreateLink(
            new SealDestinationUrl($hosts, $cipher),
            $hosts,
            $reserve,
            $shortLinks,
            $versions,
            new EffectiveStatus,
            app(TransactionManager::class),
        );

        $threw = null;

        try {
            $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/x',
                customAlias: null,
                title: null,
                expiresAt: null,
            ));
        } catch (Throwable $e) {
            $threw = $e;
        }

        expect($threw)->not->toBeNull()
            ->and(tableCounts())->toBe(['reservations' => 0, 'links' => 0, 'versions' => 0]);
    });

    it('rolls back the reservation when the short link insert fails', function () {
        $owner = createLinkOwner();
        $hosts = app(PublicHostClassifier::class);
        $cipher = app(DestinationCipher::class);
        $policy = new SlugPolicy(new ConfigReservedSlugs);
        $source = new CreateLinkSequencedSlugSource(['faillnk1']);
        $generator = new SlugGenerator($source, $policy, 8, 5);
        $reserve = new ReserveSlug($policy, $generator, new EloquentSlugReservationRepository, 5);

        $failingLinks = new class implements ShortLinkRepository
        {
            public function create(
                UserId $ownerId,
                Slug $slug,
                SlugSource $slugSource,
                ?string $title,
                ?DateTimeImmutable $expiresAt,
            ): PersistedShortLink {
                throw new RuntimeException('short link insert failed');
            }
        };

        $useCase = new CreateLink(
            new SealDestinationUrl($hosts, $cipher),
            $hosts,
            $reserve,
            $failingLinks,
            new EloquentDestinationVersionRepository(
                new Uuid7LinkDestinationVersionIdGenerator,
                new LinkDestinationVersionMapper,
            ),
            new EffectiveStatus,
            app(TransactionManager::class),
        );

        $threw = null;

        try {
            $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/y',
                customAlias: null,
                title: null,
                expiresAt: null,
            ));
        } catch (RuntimeException $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(RuntimeException::class)
            ->and(tableCounts())->toBe(['reservations' => 0, 'links' => 0, 'versions' => 0]);
    });

    it('seals the destination before opening a transaction', function () {
        $owner = createLinkOwner();
        $cipher = new RecordingDestinationCipher(app(DestinationCipher::class));

        // Alias already reserved — reservation fails inside the transaction, but
        // encrypt must already have run (calls >= 1) with zero link/version rows.
        DB::table('slug_reservations')->insert([
            'slug' => 'pre-taken',
            'reserved_at' => now(),
        ]);

        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource([]), cipher: $cipher);

        $threw = null;

        try {
            $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/before',
                customAlias: 'pre-taken',
                title: null,
                expiresAt: null,
            ));
        } catch (SlugUnavailable $e) {
            $threw = $e;
        }

        expect($cipher->encryptCalls)->toBe(1)
            ->and($threw)->toBeInstanceOf(SlugUnavailable::class)
            ->and(DB::table('short_links')->count())->toBe(0)
            ->and(DB::table('link_destination_versions')->count())->toBe(0);
    });

    it('rejects an invalid destination before any write', function () {
        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource(['neveruse']));

        expect(fn () => $useCase->execute($owner, new CreateLinkInput(
            destinationUrl: 'not-a-url',
            customAlias: null,
            title: null,
            expiresAt: null,
        )))->toThrow(LinksDomainException::class);

        expect(tableCounts())->toBe(['reservations' => 0, 'links' => 0, 'versions' => 0]);
    });

    it('participates in an outer transaction so all three rows share one unit of work', function () {
        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource(['outer001']));

        $levelBefore = DB::transactionLevel();
        DB::beginTransaction();
        $levelOpened = DB::transactionLevel();

        try {
            $result = $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/outer',
                customAlias: null,
                title: null,
                expiresAt: null,
            ));

            // CreateLink's boundary completes (SAVEPOINT released) without leaving an extra open level.
            expect($result->slug)->toBe('outer001')
                ->and(tableCounts())->toBe(['reservations' => 1, 'links' => 1, 'versions' => 1])
                ->and(DB::transactionLevel())->toBe($levelOpened);

            DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        expect(DB::transactionLevel())->toBe($levelBefore)
            ->and(tableCounts())->toBe(['reservations' => 0, 'links' => 0, 'versions' => 0]);
    });

    it('rolls back reservation, link, and version together when the outer transaction aborts', function () {
        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource(['outer002']));

        DB::beginTransaction();

        try {
            $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/outer-abort',
                customAlias: null,
                title: null,
                expiresAt: null,
            ));

            expect(tableCounts())->toBe(['reservations' => 1, 'links' => 1, 'versions' => 1]);
        } finally {
            DB::rollBack();
        }

        expect(tableCounts())->toBe(['reservations' => 0, 'links' => 0, 'versions' => 0]);
    });
});

describe('CreateLink slug failure modes', function () {
    it('propagates SlugUnavailable typed with no partial state', function () {
        SlugReservationModel::query()->create([
            'slug' => 'taken-alias',
            'reserved_at' => now(),
        ]);

        $owner = createLinkOwner();
        $useCase = makeCreateLink(new CreateLinkSequencedSlugSource([]));

        $threw = null;

        try {
            $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/taken',
                customAlias: 'taken-alias',
                title: null,
                expiresAt: null,
            ));
        } catch (SlugUnavailable $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(SlugUnavailable::class)
            ->and(tableCounts())->toBe(['reservations' => 1, 'links' => 0, 'versions' => 0]);
    });

    it('propagates SlugGenerationExhausted typed with no partial state', function () {
        $owner = createLinkOwner();
        // All candidates collide.
        SlugReservationModel::query()->create(['slug' => 'aaaa1111', 'reserved_at' => now()]);
        SlugReservationModel::query()->create(['slug' => 'bbbb2222', 'reserved_at' => now()]);
        $useCase = makeCreateLink(
            new CreateLinkSequencedSlugSource(['aaaa1111', 'bbbb2222', 'aaaa1111', 'bbbb2222', 'aaaa1111']),
            maxCollisionAttempts: 5,
        );

        $threw = null;

        try {
            $useCase->execute($owner, new CreateLinkInput(
                destinationUrl: 'https://example.com/exhaust',
                customAlias: null,
                title: null,
                expiresAt: null,
            ));
        } catch (SlugGenerationExhausted $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(SlugGenerationExhausted::class)
            ->and(DB::table('short_links')->count())->toBe(0)
            ->and(DB::table('link_destination_versions')->count())->toBe(0)
            ->and(DB::table('slug_reservations')->count())->toBe(2);
    });

    it('sets initial state is_enabled true, blocked_at null, version 1', function () {
        $owner = createLinkOwner();
        $result = makeCreateLink(new CreateLinkSequencedSlugSource(['state001']))->execute(
            $owner,
            new CreateLinkInput('https://example.com/state', null, null, null),
        );

        $row = ShortLinkModel::query()->findOrFail($result->id);

        expect($row->is_enabled)->toBeTrue()
            ->and($row->blocked_at)->toBeNull()
            ->and($row->version)->toBe(1)
            ->and($result->isEnabled)->toBeTrue()
            ->and($result->blockedAt)->toBeNull()
            ->and($result->version)->toBe(1);
    });

    it('stores slug_source automatic vs custom correctly', function () {
        $owner = createLinkOwner();

        $auto = makeCreateLink(new CreateLinkSequencedSlugSource(['srcauto1']))->execute(
            $owner,
            new CreateLinkInput('https://example.com/a', null, null, null),
        );
        $custom = makeCreateLink(new CreateLinkSequencedSlugSource([]))->execute(
            $owner,
            new CreateLinkInput('https://example.com/b', 'custom-src', null, null),
        );

        expect($auto->slugSource)->toBe(SlugSource::Automatic)
            ->and($custom->slugSource)->toBe(SlugSource::Custom)
            ->and(ShortLinkModel::query()->findOrFail($auto->id)->slug_source)->toBe('automatic')
            ->and(ShortLinkModel::query()->findOrFail($custom->id)->slug_source)->toBe('custom');
    });
});
