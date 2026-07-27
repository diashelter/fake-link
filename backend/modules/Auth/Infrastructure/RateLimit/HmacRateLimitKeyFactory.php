<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\RateLimit;

final class HmacRateLimitKeyFactory
{
    public function forRegistrationIp(string $canonicalIp): string
    {
        return hash_hmac(
            'sha256',
            'registration:'.$canonicalIp,
            (string) config('auth.rate_limit_hmac_key'),
        );
    }
}
