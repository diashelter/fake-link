<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Idempotency architectural boundaries (idempotency slice — LNK-43/LNK-44)
|--------------------------------------------------------------------------
|
| Discrimination sensor — the mutation each rule is built to kill:
|
| R1 "Links Domain stays free of Laravel": a mutant that adds
|    `use Illuminate\Support\Facades\DB` or `config('links...')` inside
|    Modules\Links\Domain\* is killed.
|
| R2 "CreateLinkController does not persist or open transactions": a mutant
|    that imports Eloquent models, DB facade, or a transaction manager into
|    Modules\Links\Infrastructure\Http\Controllers\* is killed — HTTP adapters
|    delegate to UseCases only.
|
*/

arch('Links Domain stays free of Laravel for idempotency')
    ->expect('Modules\Links\Domain')
    ->not->toUse([
        'config',
        'Illuminate\Support\Facades',
        'Illuminate\Database',
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades\DB',
    ]);

arch('Links CreateLink controllers do not persist or open transactions')
    ->expect('Modules\Links\Infrastructure\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database',
        'Illuminate\Database\Eloquent',
        'Modules\Links\Infrastructure\Persistence',
        'Modules\Links\Contracts\Services\TransactionManager',
        'Modules\Links\Infrastructure\Persistence\Eloquent\Models',
    ]);
