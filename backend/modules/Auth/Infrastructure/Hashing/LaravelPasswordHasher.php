<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Hashing;

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Contracts\Services\PasswordHasher;

final class LaravelPasswordHasher implements PasswordHasher
{
    public function hash(string $plainText): string
    {
        return Hash::make($plainText);
    }

    public function verify(string $plainText, string $hash): bool
    {
        return Hash::check($plainText, $hash);
    }
}
