<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Resources;

use Modules\Links\DTOs\Output\LinkSummaryRecord;

final class LinkSummaryResource
{
    /**
     * @return array{
     *     id: string,
     *     slug: string,
     *     short_url: string,
     *     title: string|null,
     *     slug_source: string,
     *     is_enabled: bool,
     *     status: string,
     *     expires_at: string|null,
     *     created_at: string,
     *     updated_at: string
     * }
     */
    public static function toArray(LinkSummaryRecord $link): array
    {
        $base = rtrim((string) config('links.short_url.base_url'), '/');

        return [
            'id' => $link->id,
            'slug' => $link->slug,
            'short_url' => $base.'/'.$link->slug,
            'title' => $link->title,
            'slug_source' => $link->slugSource->value,
            'is_enabled' => $link->isEnabled,
            'status' => $link->status->value,
            'expires_at' => $link->expiresAt !== null ? LinkDetailResource::formatUtc($link->expiresAt) : null,
            'created_at' => LinkDetailResource::formatUtc($link->createdAt),
            'updated_at' => LinkDetailResource::formatUtc($link->updatedAt),
        ];
    }
}
