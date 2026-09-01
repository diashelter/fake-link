<?php

declare(strict_types=1);

use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Domain\ValueObjects\Slug;

/*
|--------------------------------------------------------------------------
| Slug policy architectural boundaries (slug-policy slice — SLG-15, SLG-16)
|--------------------------------------------------------------------------
|
| Discrimination sensor — the mutation each rule is built to kill:
|
| R1 "Links Domain stays free of the framework": a mutant that adds
|    `config('links.slug.length')` or `use Illuminate\Support\Facades\DB`
|    inside Modules\Links\Domain\* is killed — the denylist and ceilings
|    must reach the domain through injected ports, never config().
|
| R2 "Links Domain does not depend on Infrastructure": a mutant that adds
|    `use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel`
|    (or any Infrastructure class) to a Domain service is killed.
|
| R3 "no code path removes or mutates a slug reservation": a mutant that
|    adds `SlugReservationModel::query()->where(...)->delete()` /
|    `->forceDelete()` / `->truncate()` / `->update([...])`, or a
|    `deleteBySlug()` method on SlugReservationRepository, is killed — the
|    reservation namespace is permanent and append-only.
|
| R4 "Slug value object exposes no mutator": a mutant that drops `readonly`
|    from the class or adds a public `withValue()` / `setValue()` /
|    `rename()` method is killed — a constructed Slug is immutable.
|
*/

arch('Links Domain stays free of config() and the framework')
    ->expect('Modules\Links\Domain')
    ->not->toUse([
        'config',
        'Illuminate\Support\Facades',
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades\DB',
    ]);

arch('Links Domain does not depend on Infrastructure')
    ->expect('Modules\Links\Domain')
    ->not->toUse('Modules\Links\Infrastructure');

test('no code path removes, truncates or updates a slug reservation', function () {
    // The port itself must offer only append + read.
    $portMethods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(SlugReservationRepository::class))->getMethods(),
    );
    sort($portMethods);

    expect($portMethods)->toBe(['existsWithoutLink', 'reserve']);

    // No production file that touches the reservation table/model may call a
    // removal or mutation method against it.
    $root = dirname(__DIR__, 2).'/modules/Links';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    $forbidden = ['->delete(', '->forceDelete(', '->truncate(', '->update(', '::destroy('];
    $offenders = [];

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        if (str_contains($path, '/Tests/')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'slug_reservations') && ! str_contains($source, 'SlugReservationModel')) {
            continue;
        }

        foreach ($forbidden as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = basename($path).' contains '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the Slug value object exposes no mutator and stays readonly', function () {
    $reflection = new ReflectionClass(Slug::class);

    expect($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->isFinal())->toBeTrue();

    $publicMethods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($publicMethods);

    // Two named constructors plus three pure accessors — nothing that returns
    // a Slug with a changed value.
    expect($publicMethods)->toBe(['equals', 'fromCustomAlias', 'fromGenerated', 'source', 'value']);
});
