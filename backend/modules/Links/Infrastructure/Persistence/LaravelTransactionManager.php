<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Links\Contracts\Services\TransactionManager;

/**
 * Laravel DB adapter for TransactionManager.
 *
 * Always opens via DB::transaction so CreateLink owns a rollback boundary
 * (a SAVEPOINT when an outer unit of work is already open). Work remains
 * atomic with the outer transaction: an outer rollback discards everything.
 */
final class LaravelTransactionManager implements TransactionManager
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed
    {
        return DB::transaction(static fn () => $callback());
    }
}
