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

arch('shared does not depend on domain modules')
    ->expect('Modules\Shared')
    ->not->toUse([
        'Modules\Auth',
        'Modules\Links',
        'Modules\Redirects',
        'Modules\Analytics',
        'Modules\Operations',
    ]);
