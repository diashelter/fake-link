<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

/**
 * Application transaction boundary. UseCases never import Laravel DB facades.
 */
interface TransactionManager
{
    /**
     * Run $callback inside a transaction. When a transaction is already open,
     * participates via SAVEPOINT so the caller still owns a single unit of work
     * while CreateLink retains a rollback boundary for its writes.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed;
}
