<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\Input\ListLinksQuery;
use Modules\Links\DTOs\Output\LinkPageRecords;
use Modules\Links\DTOs\Output\LinkSummaryRecord;
use Modules\Links\DTOs\Output\PersistedLinkDetail;
use RuntimeException;

final class EloquentLinkQueryRepository implements LinkQueryRepository
{
    private const LIKE_ESCAPE = '\\';

    public function listForOwner(
        UserId $ownerId,
        ListLinksQuery $query,
        ?CursorAnchor $anchor,
        DateTimeImmutable $now,
    ): LinkPageRecords {
        $nowUtc = Carbon::instance($now->setTimezone(new DateTimeZone('UTC')));

        $builder = DB::table('short_links')
            ->select([
                'id',
                'slug',
                'slug_source',
                'title',
                'is_enabled',
                'expires_at',
                'created_at',
                'updated_at',
            ])
            ->selectRaw(
                $this->effectiveStatusSql().' as effective_status',
                [$nowUtc],
            )
            ->where('user_id', $ownerId->value());

        $this->applySearch($builder, $query->search);
        $this->applyStatus($builder, $query->status, $nowUtc);
        $this->applyKeyset($builder, $anchor);

        $rows = $builder
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->perPage + 1)
            ->get();

        $hasMore = $rows->count() > $query->perPage;
        $page = $hasMore ? $rows->take($query->perPage) : $rows;

        $items = [];
        foreach ($page as $row) {
            $items[] = $this->toSummary($row);
        }

        return new LinkPageRecords($items, $hasMore);
    }

    public function findForOwner(UserId $ownerId, ShortLinkId $linkId): ?PersistedLinkDetail
    {
        $link = DB::table('short_links')
            ->where('user_id', $ownerId->value())
            ->where('id', $linkId->value())
            ->first();

        if ($link === null) {
            return null;
        }

        $version = DB::table('link_destination_versions')
            ->where('short_link_id', $link->id)
            ->whereNull('valid_to')
            ->first();

        if ($version === null) {
            return null;
        }

        $slugSource = SlugSource::from((string) $link->slug_source);

        return new PersistedLinkDetail(
            id: ShortLinkId::fromString((string) $link->id),
            slug: $slugSource === SlugSource::Custom
                ? Slug::fromCustomAlias((string) $link->slug)
                : Slug::fromGenerated((string) $link->slug),
            slugSource: $slugSource,
            destination: EncryptedDestination::fromParts(
                (string) $version->destination_url,
                (string) $version->key_id,
            ),
            title: $link->title === null ? null : (string) $link->title,
            isEnabled: (bool) $link->is_enabled,
            blockedAt: $this->nullableInstant($link->blocked_at),
            expiresAt: $this->nullableInstant($link->expires_at),
            version: (int) $link->version,
            createdAt: $this->instant($link->created_at),
            updatedAt: $this->instant($link->updated_at),
        );
    }

    private function applySearch(Builder $builder, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $term = $this->escapeLike(mb_strtolower($search));

        $builder->where(function (Builder $inner) use ($term): void {
            $inner->whereRaw("lower(title) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", ['%'.$term.'%'])
                ->orWhereRaw("slug LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$term.'%']);
        });
    }

    private function applyStatus(Builder $builder, ?LinkStatus $status, Carbon $now): void
    {
        if ($status === null) {
            return;
        }

        match ($status) {
            LinkStatus::Blocked => $builder->whereNotNull('blocked_at'),
            LinkStatus::Expired => $builder
                ->whereNull('blocked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now),
            LinkStatus::Inactive => $builder
                ->whereNull('blocked_at')
                ->where(function (Builder $inner) use ($now): void {
                    $inner->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->where('is_enabled', false),
            LinkStatus::Active => $builder
                ->whereNull('blocked_at')
                ->where(function (Builder $inner) use ($now): void {
                    $inner->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->where('is_enabled', true),
        };
    }

    private function applyKeyset(Builder $builder, ?CursorAnchor $anchor): void
    {
        if ($anchor === null) {
            return;
        }

        $createdAt = Carbon::instance($anchor->createdAt->setTimezone(new DateTimeZone('UTC')));

        $builder->where(function (Builder $inner) use ($createdAt, $anchor): void {
            $inner->where('created_at', '<', $createdAt)
                ->orWhere(function (Builder $tie) use ($createdAt, $anchor): void {
                    $tie->where('created_at', '=', $createdAt)
                        ->where('id', '<', $anchor->id->value());
                });
        });
    }

    private function toSummary(object $row): LinkSummaryRecord
    {
        return new LinkSummaryRecord(
            id: (string) $row->id,
            slug: (string) $row->slug,
            slugSource: SlugSource::from((string) $row->slug_source),
            title: $row->title === null ? null : (string) $row->title,
            isEnabled: (bool) $row->is_enabled,
            status: LinkStatus::fromString((string) $row->effective_status),
            expiresAt: $this->nullableInstant($row->expires_at),
            createdAt: $this->instant($row->created_at),
            updatedAt: $this->instant($row->updated_at),
        );
    }

    private function effectiveStatusSql(): string
    {
        return <<<'SQL'
CASE
    WHEN blocked_at IS NOT NULL THEN 'blocked'
    WHEN expires_at IS NOT NULL AND expires_at <= ? THEN 'expired'
    WHEN is_enabled = false THEN 'inactive'
    ELSE 'active'
END
SQL;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $value,
        );
    }

    private function nullableInstant(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return $this->instant($value);
    }

    private function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Expected a timestamp value.');
        }

        return new DateTimeImmutable($value);
    }
}
