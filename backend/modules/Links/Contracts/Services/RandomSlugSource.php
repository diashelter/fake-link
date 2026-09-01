<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

/**
 * Cryptographically secure source of slug candidates. Isolable so tests can
 * inject a deterministic sequence to force collisions.
 */
interface RandomSlugSource
{
    /**
     * Return a string of exactly $length characters, each drawn uniformly at
     * random from $alphabet.
     */
    public function candidate(int $length, string $alphabet): string;
}
