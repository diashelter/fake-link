<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Hashing;

use Modules\Auth\Contracts\Services\TokenHasher;

final class Sha256TokenHasher implements TokenHasher
{
    public function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    public function verify(string $plainText, string $hash): bool
    {
        return hash_equals($hash, $this->hash($plainText));
    }
}
