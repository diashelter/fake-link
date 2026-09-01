<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugUnavailable;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;

/**
 * Eloquent adapter for slug reservations.
 *
 * reserve() is a bare INSERT — no availability SELECT first — so the primary
 * key is the sole authority on collisions. A unique-constraint violation is
 * translated into the uniform SlugUnavailable; any other database error
 * propagates untouched.
 */
final class EloquentSlugReservationRepository implements SlugReservationRepository
{
    public function reserve(Slug $slug): void
    {
        try {
            SlugReservationModel::query()->create([
                'slug' => $slug->value(),
                'reserved_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw SlugUnavailable::reserved();
        }
    }

    public function existsWithoutLink(Slug $slug): bool
    {
        return DB::table('slug_reservations as sr')
            ->leftJoin('short_links as sl', 'sl.slug', '=', 'sr.slug')
            ->where('sr.slug', $slug->value())
            ->whereNull('sl.slug')
            ->exists();
    }
}
