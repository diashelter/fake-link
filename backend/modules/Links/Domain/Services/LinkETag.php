<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Domain\Enums\LinkStatus;

/**
 * Computes a strong, opaque ETag from the canonical short-link state tuple.
 *
 * Tuple: id, slug, normalized destination URL, title, is_enabled, expires_at,
 * blocked_at, updated_at, effective status. version and user_id are intentionally
 * excluded so they cannot be recovered from the header value.
 */
final class LinkETag
{
    private const UTC = 'UTC';

    public function __construct(
        private readonly ETagSigningKey $signingKey,
    ) {}

    public function for(
        string $id,
        string $slug,
        string $normalizedDestinationUrl,
        ?string $title,
        bool $isEnabled,
        ?DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $blockedAt,
        DateTimeImmutable $updatedAt,
        LinkStatus $effectiveStatus,
    ): string {
        $payload = implode("\n", [
            $id,
            $slug,
            $normalizedDestinationUrl,
            $title ?? '',
            $isEnabled ? '1' : '0',
            $this->formatInstant($expiresAt),
            $this->formatInstant($blockedAt),
            $this->formatInstant($updatedAt),
            $effectiveStatus->value,
        ]);

        $digest = hash_hmac('sha256', $payload, $this->signingKey->value());

        return '"'.$digest.'"';
    }

    private function formatInstant(?DateTimeImmutable $instant): string
    {
        if ($instant === null) {
            return '';
        }

        return $instant
            ->setTimezone(new DateTimeZone(self::UTC))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
