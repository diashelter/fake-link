<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\RateLimit;

use Modules\Auth\Domain\ValueObjects\AuthTokenId;
use Modules\Auth\Domain\ValueObjects\UserId;

final class LinkRateLimitKeyFactory
{
    public function forLinkCreation(UserId $userId): string
    {
        return hash_hmac(
            'sha256',
            'links:create:'.$userId->value(),
            (string) config('links.rate_limit_hmac_key'),
        );
    }

    public function forPrivateRead(AuthTokenId $tokenId): string
    {
        return hash_hmac(
            'sha256',
            'links:private-read:'.$tokenId->value(),
            (string) config('links.rate_limit_hmac_key'),
        );
    }
}
