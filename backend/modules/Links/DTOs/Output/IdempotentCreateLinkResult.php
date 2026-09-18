<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

/**
 * Outcome of CreateIdempotentLink: a fresh creation or an exact snapshot replay.
 */
final readonly class IdempotentCreateLinkResult
{
    public function __construct(
        public bool $replayed,
        public IdempotencyResponseSnapshot $snapshot,
        public ?CreatedLinkDto $created = null,
    ) {}
}
