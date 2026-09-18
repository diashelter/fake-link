<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugReservationMissing;
use Modules\Links\Infrastructure\Identity\Uuid7ShortLinkIdGenerator;
use Modules\Links\Infrastructure\Persistence\Eloquent\Mappers\ShortLinkMapper;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    $this->repo = new EloquentShortLinkRepository(
        new Uuid7ShortLinkIdGenerator,
        new ShortLinkMapper,
    );
});

function shortLinkOwnerId(): UserId
{
    $userId = (string) Str::uuid7();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Short Link Repo User',
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

function reserveSlug(string $slug): Slug
{
    $value = Slug::fromCustomAlias($slug);
    SlugReservationModel::query()->create([
        'slug' => $value->value(),
        'reserved_at' => now(),
    ]);

    return $value;
}

describe('ShortLinkRepository port', function () {
    it('exposes only create — no update path for slug or user_id', function () {
        $methods = collect((new ReflectionClass(ShortLinkRepository::class))->getMethods())
            ->map(fn (ReflectionMethod $m) => $m->getName())
            ->sort()
            ->values()
            ->all();

        expect($methods)->toBe(['create']);
    });

    it('Eloquent adapter also exposes no update path for slug or user_id', function () {
        $methods = collect((new ReflectionClass(EloquentShortLinkRepository::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === EloquentShortLinkRepository::class)
            ->map(fn (ReflectionMethod $m) => $m->getName())
            ->sort()
            ->values()
            ->all();

        expect($methods)->toBe(['__construct', 'create']);
    });
});

describe('EloquentShortLinkRepository::create', function () {
    it('inserts a short link with an application-generated UUID v7', function () {
        $owner = shortLinkOwnerId();
        $slug = reserveSlug('repo-slug-one');

        $persisted = $this->repo->create(
            ownerId: $owner,
            slug: $slug,
            slugSource: SlugSource::Custom,
            title: 'Hello',
            expiresAt: null,
        );

        expect($persisted->id->value())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i')
            ->and($persisted->userId->equals($owner))->toBeTrue()
            ->and($persisted->slug->value())->toBe('repo-slug-one')
            ->and($persisted->slugSource)->toBe(SlugSource::Custom)
            ->and($persisted->title)->toBe('Hello')
            ->and($persisted->isEnabled)->toBeTrue()
            ->and($persisted->blockedAt)->toBeNull()
            ->and($persisted->expiresAt)->toBeNull()
            ->and($persisted->version)->toBe(1);

        $row = ShortLinkModel::query()->findOrFail($persisted->id->value());

        expect($row->id)->toBe($persisted->id->value())
            ->and($row->user_id)->toBe($owner->value())
            ->and($row->slug)->toBe('repo-slug-one')
            ->and($row->is_enabled)->toBeTrue()
            ->and($row->blocked_at)->toBeNull()
            ->and($row->version)->toBe(1);
    });

    it('persists expires_at when provided', function () {
        $owner = shortLinkOwnerId();
        $slug = reserveSlug('repo-expires');
        $expiresAt = new DateTimeImmutable('2030-01-15T12:00:00Z');

        $persisted = $this->repo->create(
            ownerId: $owner,
            slug: $slug,
            slugSource: SlugSource::Custom,
            title: null,
            expiresAt: $expiresAt,
        );

        expect($persisted->expiresAt)->not->toBeNull()
            ->and($persisted->expiresAt->format('Y-m-d\TH:i:s\Z'))->toBe('2030-01-15T12:00:00Z');
    });

    it('records slug_source automatic for generated slugs', function () {
        $owner = shortLinkOwnerId();
        $candidate = 'abc12def';
        SlugReservationModel::query()->create([
            'slug' => $candidate,
            'reserved_at' => now(),
        ]);
        $slug = Slug::fromGenerated($candidate);

        $persisted = $this->repo->create(
            ownerId: $owner,
            slug: $slug,
            slugSource: SlugSource::Automatic,
            title: null,
            expiresAt: null,
        );

        expect($persisted->slugSource)->toBe(SlugSource::Automatic)
            ->and($persisted->slug->value())->toBe($candidate);
    });

    it('maps a missing slug reservation FK violation to SlugReservationMissing', function () {
        $owner = shortLinkOwnerId();
        $slug = Slug::fromCustomAlias('no-reservation');

        // Nested transaction = SAVEPOINT so the FK abort does not poison the
        // surrounding RefreshDatabase transaction used by later assertions.
        expect(fn () => DB::transaction(fn () => $this->repo->create(
            ownerId: $owner,
            slug: $slug,
            slugSource: SlugSource::Custom,
            title: null,
            expiresAt: null,
        )))->toThrow(SlugReservationMissing::class);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(ShortLinkModel::query()->where('slug', 'no-reservation')->exists())->toBeFalse();
    });

    it('does not echo the slug in SlugReservationMissing', function () {
        $owner = shortLinkOwnerId();
        $marker = 'secret-slug-marker-xyz';
        $thrown = null;

        try {
            DB::transaction(fn () => $this->repo->create(
                ownerId: $owner,
                slug: Slug::fromCustomAlias($marker),
                slugSource: SlugSource::Custom,
                title: null,
                expiresAt: null,
            ));
        } catch (SlugReservationMissing $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(SlugReservationMissing::class)
            ->and($thrown->getMessage())->not->toContain($marker)
            ->and($thrown->errorCode())->toBe(SlugReservationMissing::ERROR_CODE);
    });

    it('participates in the caller transaction — a rollback discards the insert', function () {
        $owner = shortLinkOwnerId();
        $slug = reserveSlug('rolled-link');

        try {
            DB::transaction(function () use ($owner, $slug) {
                $this->repo->create(
                    ownerId: $owner,
                    slug: $slug,
                    slugSource: SlugSource::Custom,
                    title: null,
                    expiresAt: null,
                );

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        // @phpstan-ignore staticMethod.dynamicCall
        expect(ShortLinkModel::query()->where('slug', 'rolled-link')->exists())->toBeFalse();
    });

    it('leaves a committed insert visible after the caller transaction succeeds', function () {
        $owner = shortLinkOwnerId();
        $slug = reserveSlug('committed-link');

        $persisted = DB::transaction(fn () => $this->repo->create(
            ownerId: $owner,
            slug: $slug,
            slugSource: SlugSource::Custom,
            title: null,
            expiresAt: null,
        ));

        // @phpstan-ignore staticMethod.dynamicCall
        expect(ShortLinkModel::query()->whereKey($persisted->id->value())->exists())->toBeTrue();
    });
});
