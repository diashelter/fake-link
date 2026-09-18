<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\LinkDestinationVersionMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentDestinationVersionRepository;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    $this->repo = new EloquentDestinationVersionRepository(
        new Uuid7LinkDestinationVersionIdGenerator,
        new LinkDestinationVersionMapper,
    );
});

function destinationOwnerLink(): ShortLinkModel
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Destination Repo User',
        'email' => Str::uuid7().'@example.com',
        'password' => 'hash',
        'status' => 'active',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reservation = SlugReservationModel::query()->create([
        'slug' => 'dst-'.substr((string) Str::uuid7(), 0, 8),
        'reserved_at' => now(),
    ]);

    return ShortLinkModel::query()->create([
        'id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'slug' => $reservation->slug,
        'slug_source' => 'automatic',
        'is_enabled' => true,
        'version' => 1,
    ]);
}

function sealedDestination(string $plaintextUrl = 'https://example.com/secret-path'): EncryptedDestination
{
    return app(DestinationCipher::class)
        ->encrypt(
            DestinationUrl::fromString(
                $plaintextUrl,
                app(PublicHostClassifier::class),
            ),
        );
}

describe('DestinationVersionRepository port', function () {
    it('exposes only openFirstVersion', function () {
        $methods = collect((new ReflectionClass(DestinationVersionRepository::class))->getMethods())
            ->map(fn (ReflectionMethod $m) => $m->getName())
            ->sort()
            ->values()
            ->all();

        expect($methods)->toBe(['openFirstVersion']);
    });
});

describe('EloquentDestinationVersionRepository::openFirstVersion', function () {
    it('persists a row with valid_to null, valid_from equal to the creation instant, and key_id set', function () {
        $link = destinationOwnerLink();
        $encrypted = sealedDestination();
        $validFrom = new DateTimeImmutable('2026-06-15T10:30:00Z');

        $id = $this->repo->openFirstVersion(
            ShortLinkId::fromString($link->id),
            $encrypted,
            $validFrom,
        );

        $row = LinkDestinationVersionModel::query()->findOrFail($id->value());

        expect($row->valid_to)->toBeNull()
            ->and($row->valid_from->equalTo(Carbon::parse('2026-06-15T10:30:00Z')))->toBeTrue()
            ->and($row->key_id)->toBe($encrypted->keyId())
            ->and($row->key_id)->not->toBe('')
            ->and($row->short_link_id)->toBe($link->id)
            ->and($id->value())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    it('stores an encrypted envelope — the plaintext URL is not in the column', function () {
        $plaintext = 'https://example.com/must-not-appear-in-column';
        $link = destinationOwnerLink();
        $encrypted = sealedDestination($plaintext);

        $id = $this->repo->openFirstVersion(
            ShortLinkId::fromString($link->id),
            $encrypted,
            new DateTimeImmutable('2026-06-15T10:30:00Z'),
        );

        $stored = DB::table('link_destination_versions')->where('id', $id->value())->value('destination_url');

        expect($stored)->toBe($encrypted->envelope())
            ->and($stored)->not->toContain($plaintext)
            ->and($stored)->not->toContain('must-not-appear-in-column');
    });

    it('fails when a second current version is opened for the same short link', function () {
        $link = destinationOwnerLink();
        $encrypted = sealedDestination();
        $shortLinkId = ShortLinkId::fromString($link->id);
        $validFrom = new DateTimeImmutable('2026-06-15T10:30:00Z');

        $this->repo->openFirstVersion($shortLinkId, $encrypted, $validFrom);

        $threw = null;

        try {
            DB::transaction(fn () => $this->repo->openFirstVersion(
                $shortLinkId,
                sealedDestination('https://example.com/other'),
                $validFrom->modify('+1 second'),
            ));
        } catch (Throwable $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($threw)->toBeInstanceOf(QueryException::class);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(LinkDestinationVersionModel::query()->where('short_link_id', $link->id)->count())->toBe(1);
    });

    it('participates in the caller transaction — a rollback discards the version', function () {
        $link = destinationOwnerLink();
        $encrypted = sealedDestination();

        try {
            DB::transaction(function () use ($link, $encrypted) {
                $this->repo->openFirstVersion(
                    ShortLinkId::fromString($link->id),
                    $encrypted,
                    new DateTimeImmutable('2026-06-15T10:30:00Z'),
                );

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        // @phpstan-ignore staticMethod.dynamicCall
        expect(LinkDestinationVersionModel::query()->where('short_link_id', $link->id)->exists())->toBeFalse();
    });

    it('leaves a committed version visible after the caller transaction succeeds', function () {
        $link = destinationOwnerLink();
        $encrypted = sealedDestination();

        $id = DB::transaction(fn () => $this->repo->openFirstVersion(
            ShortLinkId::fromString($link->id),
            $encrypted,
            new DateTimeImmutable('2026-06-15T10:30:00Z'),
        ));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(LinkDestinationVersionModel::query()->whereKey($id->value())->exists())->toBeTrue();
    });

    it('allows a second open version when the first was closed (valid_to set)', function () {
        $link = destinationOwnerLink();
        $shortLinkId = ShortLinkId::fromString($link->id);
        $firstId = $this->repo->openFirstVersion(
            $shortLinkId,
            sealedDestination(),
            new DateTimeImmutable('2026-06-15T10:30:00Z'),
        );

        LinkDestinationVersionModel::query()
            ->whereKey($firstId->value())
            ->update(['valid_to' => now()]);

        $secondId = $this->repo->openFirstVersion(
            $shortLinkId,
            sealedDestination('https://example.com/next'),
            new DateTimeImmutable('2026-06-15T11:00:00Z'),
        );

        // @phpstan-ignore staticMethod.dynamicCall
        $total = LinkDestinationVersionModel::query()->where('short_link_id', $link->id)->count();
        $open = DB::table('link_destination_versions')
            ->where('short_link_id', $link->id)
            ->whereNull('valid_to')
            ->count();

        expect($secondId->equals($firstId))->toBeFalse()
            ->and($total)->toBe(2)
            ->and($open)->toBe(1);
    });
});
