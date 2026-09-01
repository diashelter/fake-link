<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentSlugReservationRepository;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\UseCases\ReserveSlug;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

/**
 * A RandomSlugSource that replays a fixed script and counts its calls.
 */
final class SequencedSlugSource implements RandomSlugSource
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

function makeReserveSlug(RandomSlugSource $source, int $maxCollisionAttempts = 5): ReserveSlug
{
    $policy = new SlugPolicy(new ConfigReservedSlugs);
    $generator = new SlugGenerator($source, $policy, 8, 5);

    return new ReserveSlug($policy, $generator, new EloquentSlugReservationRepository, $maxCollisionAttempts);
}

/** @param  list<string>  $slugs */
function preReserveGenerated(array $slugs): void
{
    $repo = new EloquentSlugReservationRepository;

    foreach ($slugs as $slug) {
        $repo->reserve(Slug::fromGenerated($slug));
    }
}

function insertCount(): int
{
    return collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn ($q) => str_starts_with(strtolower(trim($q)), 'insert into "slug_reservations"'))
        ->count();
}

describe('ReserveSlug::forAlias', function () {
    it('reserves the normalized alias in one attempt and returns a custom-source Slug', function () {
        $useCase = makeReserveSlug(new SequencedSlugSource([]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $slug = $useCase->forAlias('  My-Alias  ');

        expect($slug->value())->toBe('my-alias')
            ->and($slug->source())->toBe(SlugSource::Custom)
            ->and(insertCount())->toBe(1);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->whereKey('my-alias')->exists())->toBeTrue();
    });

    it('propagates SlugUnavailable without retrying or writing when the alias is already reserved', function () {
        preReserveGenerated(['takenone']);
        $useCase = makeReserveSlug(new SequencedSlugSource([]));

        $threw = null;

        try {
            // Nested transaction = SAVEPOINT so the collision does not poison
            // the surrounding RefreshDatabase transaction.
            DB::transaction(fn () => $useCase->forAlias('takenone'));
        } catch (Throwable $e) {
            $threw = $e;
        }

        expect($threw)->toBeInstanceOf(SlugUnavailable::class);

        // No new row: the single pre-existing reservation is all there is.
        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->count())->toBe(1);
    });

    it('propagates SlugPolicyException for a denylisted alias and never touches the database', function () {
        $useCase = makeReserveSlug(new SequencedSlugSource([]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        expect(fn () => $useCase->forAlias('ADMIN'))->toThrow(SlugPolicyException::class);
        expect(insertCount())->toBe(0);
    });

    it('propagates SlugPolicyException for a structurally invalid alias and never touches the database', function () {
        $useCase = makeReserveSlug(new SequencedSlugSource([]));

        DB::flushQueryLog();
        DB::enableQueryLog();

        expect(fn () => $useCase->forAlias('ab'))->toThrow(SlugPolicyException::class);
        expect(insertCount())->toBe(0);
    });
});

describe('ReserveSlug::automatic', function () {
    it('reserves a generated slug and returns an automatic-source 8-character Slug', function () {
        $source = new SequencedSlugSource(['freeslug']);
        $useCase = makeReserveSlug($source);

        $slug = $useCase->automatic();

        expect($slug->value())->toBe('freeslug')
            ->and($slug->value())->toMatch('/^[a-z0-9]{8}$/')
            ->and($slug->source())->toBe(SlugSource::Automatic)
            ->and($source->calls)->toBe(1);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->whereKey('freeslug')->exists())->toBeTrue();
    });

    it('retries after a collision and succeeds on the fifth attempt', function () {
        preReserveGenerated(['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd']);
        $source = new SequencedSlugSource(['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'winnerxx']);
        $useCase = makeReserveSlug($source);

        $slug = $useCase->automatic();

        expect($slug->value())->toBe('winnerxx')
            ->and($source->calls)->toBe(5);
    });

    it('stops the moment an attempt succeeds and draws no further candidates', function () {
        preReserveGenerated(['aaaaaaaa', 'bbbbbbbb']);
        // A fourth candidate is available but must never be drawn.
        $source = new SequencedSlugSource(['aaaaaaaa', 'bbbbbbbb', 'winner33', 'unused44']);
        $useCase = makeReserveSlug($source);

        $slug = $useCase->automatic();

        expect($slug->value())->toBe('winner33')
            ->and($source->calls)->toBe(3);
    });

    it('fails with SlugGenerationExhausted after five collisions, drawing exactly five candidates and persisting nothing new', function () {
        $collisions = ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'eeeeeeee'];
        preReserveGenerated($collisions);
        $source = new SequencedSlugSource($collisions);
        $useCase = makeReserveSlug($source);

        expect(fn () => $useCase->automatic())->toThrow(SlugGenerationExhausted::class);
        expect($source->calls)->toBe(5);

        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->count())->toBe(5); // only the pre-reserved rows
    });

    it('never makes a sixth attempt even when more candidates are available', function () {
        $collisions = ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'eeeeeeee', 'ffffffff'];
        preReserveGenerated($collisions);
        $source = new SequencedSlugSource($collisions);
        $useCase = makeReserveSlug($source);

        try {
            $useCase->automatic();
        } catch (SlugGenerationExhausted) {
            // expected
        }

        expect($source->calls)->toBe(5);
    });

    it('takes the collision ceiling from config, not a hardcoded 5', function () {
        config(['links.slug.max_collision_attempts' => 2]);

        $collisions = ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'eeeeeeee'];
        preReserveGenerated($collisions);

        $source = new SequencedSlugSource($collisions);
        $this->app->instance(RandomSlugSource::class, $source);

        $useCase = app(ReserveSlug::class);

        expect(fn () => $useCase->automatic())->toThrow(SlugGenerationExhausted::class);
        expect($source->calls)->toBe(2); // ceiling honoured from config
    });

    it('participates in the caller transaction — a rollback discards the automatic reservation', function () {
        $source = new SequencedSlugSource(['freeaaaa']);
        $useCase = makeReserveSlug($source);

        try {
            DB::transaction(function () use ($useCase) {
                $useCase->automatic();

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        // @phpstan-ignore staticMethod.dynamicCall
        expect(SlugReservationModel::query()->whereKey('freeaaaa')->exists())->toBeFalse();
    });
});
