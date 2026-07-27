<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\RateLimit;

use Modules\Auth\Domain\ValueObjects\UserId;

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

    public function forEmailVerificationResend(UserId $userId): string
    {
        return hash_hmac(
            'sha256',
            'email-verification:resend:'.$userId->value(),
            (string) config('auth.rate_limit_hmac_key'),
        );
    }

    public function forEmailVerificationVerify(UserId $userId): string
    {
        return hash_hmac(
            'sha256',
            'email-verification:verify:'.$userId->value(),
            (string) config('auth.rate_limit_hmac_key'),
        );
    }
}
