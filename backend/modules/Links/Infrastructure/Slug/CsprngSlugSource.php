<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Slug;

use Modules\Links\Contracts\Services\RandomSlugSource;

/**
 * Draws each position independently with random_int(), the system CSPRNG.
 * Picking an index in [0, strlen($alphabet) - 1] avoids the modulo bias that
 * would come from reducing raw random bytes.
 */
final class CsprngSlugSource implements RandomSlugSource
{
    public function candidate(int $length, string $alphabet): string
    {
        $highestIndex = strlen($alphabet) - 1;

        $candidate = '';

        for ($position = 0; $position < $length; $position++) {
            $candidate .= $alphabet[random_int(0, $highestIndex)];
        }

        return $candidate;
    }
}
