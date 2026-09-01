<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

/**
 * Exposes the reserved-word denylist to the domain without coupling it to
 * config(). The comparison is exact equality against an already-normalized
 * slug value.
 */
interface ReservedSlugs
{
    public function contains(string $normalizedSlug): bool;
}
