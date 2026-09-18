<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Throwable;

/**
 * Deletes expired idempotency_keys rows in a single configured batch.
 *
 * Safe to re-run: only rows with expires_at <= now() are removed.
 */
final class PruneExpiredIdempotencyKeys extends Command
{
    protected $signature = 'links:prune-idempotency';

    protected $description = 'Prune expired link-creation idempotency records';

    public function handle(IdempotencyKeyRepository $idempotencyKeys): int
    {
        $batchSize = (int) config('links.idempotency.prune_batch_size', 1000);

        if ($batchSize < 1) {
            $this->error('links.idempotency.prune_batch_size must be >= 1.');

            return self::FAILURE;
        }

        /** @var DateTimeImmutable $now */
        $now = now()->utc()->toDateTimeImmutable();

        try {
            $deleted = $idempotencyKeys->deleteExpired($now, $batchSize);
        } catch (Throwable $e) {
            $this->error('Prune failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pruned {$deleted} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
