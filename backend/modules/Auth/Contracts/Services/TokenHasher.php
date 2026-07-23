<?php

declare(strict_types=1);

namespace Modules\Auth\Contracts\Services;

interface TokenHasher
{
    public function hash(string $plainText): string;

    public function verify(string $plainText, string $hash): bool;
}
