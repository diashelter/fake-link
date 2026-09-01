<?php

declare(strict_types=1);

namespace Modules\Links\UseCases;

use Illuminate\Support\Facades\DB;
use Modules\Links\Contracts\Repositories\SlugReservationRepository;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugUnavailable;

/**
 * Reserves a slug for a link, either from a user-supplied alias or generated.
 *
 * A custom alias is reserved in a single attempt — it is the user's choice and
 * is never silently retried or altered. A generated slug is retried on
 * collision up to a configured ceiling; each attempt runs in its own nested
 * transaction (a SAVEPOINT when the caller already has one open) so a
 * primary-key collision rolls back that attempt without poisoning the
 * surrounding transaction. This use case never opens the outer transaction
 * that ties the reservation to the link — that belongs to link-creation.
 */
final readonly class ReserveSlug
{
    public function __construct(
        private SlugPolicy $policy,
        private SlugGenerator $generator,
        private SlugReservationRepository $reservations,
        private int $maxCollisionAttempts,
    ) {}

    public function forAlias(string $rawAlias): Slug
    {
        $slug = $this->policy->fromCustomAlias($rawAlias);

        $this->reservations->reserve($slug);

        return $slug;
    }

    public function automatic(): Slug
    {
        for ($attempt = 1; $attempt <= $this->maxCollisionAttempts; $attempt++) {
            $slug = $this->generator->generate();

            try {
                DB::transaction(fn () => $this->reservations->reserve($slug));

                return $slug;
            } catch (SlugUnavailable) {
                // Collision — discard this candidate and generate a fresh one.
            }
        }

        throw SlugGenerationExhausted::exhausted();
    }
}
