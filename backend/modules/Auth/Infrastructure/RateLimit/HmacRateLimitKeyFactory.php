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

    public function forLoginEmailIp(string $canonicalIp, string $normalizedOrSentinelEmail): string
    {
        return hash_hmac(
            'sha256',
            'login:email-ip:'.$canonicalIp.':'.$normalizedOrSentinelEmail,
            (string) config('auth.rate_limit_hmac_key'),
        );
    }

    public function forLoginIp(string $canonicalIp): string
    {
        return hash_hmac(
            'sha256',
            'login:ip:'.$canonicalIp,
            (string) config('auth.rate_limit_hmac_key'),
        );
    }
}
