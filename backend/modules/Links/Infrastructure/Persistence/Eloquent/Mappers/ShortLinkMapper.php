<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Mappers;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\Output\PersistedShortLink;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;

final class ShortLinkMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toPersistence(
        ShortLinkId $id,
        UserId $ownerId,
        Slug $slug,
        SlugSource $slugSource,
        ?string $title,
        ?DateTimeImmutable $expiresAt,
    ): array {
        return [
            'id' => $id->value(),
            'user_id' => $ownerId->value(),
            'slug' => $slug->value(),
            'slug_source' => $slugSource->value,
            'title' => $title,
            'is_enabled' => true,
            'blocked_at' => null,
            'expires_at' => $expiresAt !== null ? Carbon::instance($expiresAt) : null,
            'version' => 1,
        ];
    }

    public function toPersisted(ShortLinkModel $model): PersistedShortLink
    {
        $slugSource = SlugSource::from($model->slug_source);

        return new PersistedShortLink(
            id: ShortLinkId::fromString($model->id),
            userId: UserId::fromString($model->user_id),
            slug: $slugSource === SlugSource::Custom
                ? Slug::fromCustomAlias($model->slug)
                : Slug::fromGenerated($model->slug),
            slugSource: $slugSource,
            title: $model->title,
            isEnabled: $model->is_enabled,
            blockedAt: $model->blocked_at !== null
                ? DateTimeImmutable::createFromInterface($model->blocked_at)
                : null,
            expiresAt: $model->expires_at !== null
                ? DateTimeImmutable::createFromInterface($model->expires_at)
                : null,
            version: $model->version,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($model->updated_at),
        );
    }
}
