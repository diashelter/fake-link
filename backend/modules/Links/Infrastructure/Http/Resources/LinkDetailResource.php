<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\DTOs\Output\LinkDetailDto;

final class LinkDetailResource
{
    /**
     * @return array{
     *     id: string,
     *     slug: string,
     *     short_url: string,
     *     destination_url: string,
     *     title: string|null,
     *     slug_source: string,
     *     is_enabled: bool,
     *     status: string,
     *     expires_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public static function toArray(CreatedLinkDto|LinkDetailDto $link): array
    {
        $base = rtrim((string) config('links.short_url.base_url'), '/');

        return [
            'id' => $link->id,
            'slug' => $link->slug,
            'short_url' => $base.'/'.$link->slug,
            'destination_url' => $link->destinationUrl,
            'title' => $link->title,
            'slug_source' => $link->slugSource->value,
            'is_enabled' => $link->isEnabled,
            'status' => $link->status->value,
            'expires_at' => $link->expiresAt !== null ? self::formatUtc($link->expiresAt) : null,
            'created_at' => self::formatUtc($link->createdAt),
            'updated_at' => self::formatUtc($link->updatedAt),
        ];
    }

    public static function formatUtc(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
