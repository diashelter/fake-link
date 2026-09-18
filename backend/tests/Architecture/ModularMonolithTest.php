<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Modular monolith architecture rules (docs/testing.md §3.1)
|--------------------------------------------------------------------------
|
| Skeleton-safe: Modules\{Module}\* namespaces are empty today, so those
| rules pass vacuously and will enforce seams as soon as modules appear.
| Per-module namespaces are used instead of Finder globs so an absent
| modules/ tree does not throw DirectoryNotFoundException.
|
| Discrimination (QTOOL-20): a Controller that imports or references
| Illuminate\Database\Eloquent\Model (or App\Models\*) must fail the
| "controllers do not use Eloquent models directly" rule. A mutant that
| adds `use App\Models\User;` (or Model) inside App\Http\Controllers\*
| is killed by that expectation.
|
| Discrimination (seam): a class inside Modules\Redirects that adds
| `use Modules\Links\Domain\...` or `use Modules\Links\Infrastructure\...`
| must fail the "Redirects does not reach into Links internals" rule.
| A mutant that couples Redirects to Links domain or infrastructure is
| killed by that expectation.
|
| Discrimination (destination-policy gate, LDST-21/LDST-22): a class
| anywhere outside Modules\Links\UseCases, Modules\Links\Infrastructure\Crypto
| or Modules\Links\ServiceProviders (which only references the interface to
| register its container binding, never to call it) that adds
| `use Modules\Links\Contracts\Services\DestinationCipher` must fail the
| "DestinationCipher is only depended on by ..." rule below -- proving the
| policy cannot be bypassed by a caller that reaches the cipher directly.
| A class inside Modules\Links\Domain that adds
| `use Modules\Links\Infrastructure\...` must fail the "Links Domain does
| not depend on Links Infrastructure" rule. Both were verified to fail
| against a temporary mutation before being committed; the mutation was
| discarded afterwards.
|
*/

$domainModules = [
    'Auth',
    'Links',
    'Redirects',
    'Analytics',
    'Operations',
];

arch('controllers do not use Eloquent models directly')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'App\Models',
    ]);

foreach ($domainModules as $module) {
    $moduleRoot = "Modules\\{$module}";

    arch("{$module} controllers do not use Eloquent models directly")
        ->expect("{$moduleRoot}\\Infrastructure\\Http\\Controllers")
        ->not->toUse([
            'Illuminate\Database\Eloquent\Model',
            'App\Models',
            "{$moduleRoot}\\Infrastructure\\Persistence\\Eloquent\\Models",
        ]);

    arch("{$module} Eloquent models are not used by other modules")
        ->expect("{$moduleRoot}\\Infrastructure\\Persistence\\Eloquent\\Models")
        ->toOnlyBeUsedIn($moduleRoot);
}

arch('Redirects does not reach into Links internals')
    ->expect('Modules\Redirects')
    ->not->toUse([
        'Modules\Links\Infrastructure',
        'Modules\Links\Domain',
    ]);

arch('shared does not depend on domain modules')
    ->expect('Modules\Shared')
    ->not->toUse([
        'Modules\Auth',
        'Modules\Links',
        'Modules\Redirects',
        'Modules\Analytics',
        'Modules\Operations',
    ]);

arch('DestinationCipher is only depended on by Links UseCases and Links Infrastructure/Crypto')
    ->expect('Modules\Links\Contracts\Services\DestinationCipher')
    ->toOnlyBeUsedIn([
        'Modules\Links\UseCases',
        'Modules\Links\Infrastructure\Crypto',
        // LinksServiceProvider references the interface only to register its container
        // binding (an array key/value pair) — it never calls encrypt()/decrypt() itself,
        // so it is not a caller that could bypass the policy.
        'Modules\Links\ServiceProviders',
    ]);

arch('Links Domain does not depend on Links Infrastructure')
    ->expect('Modules\Links\Domain')
    ->not->toUse('Modules\Links\Infrastructure');
