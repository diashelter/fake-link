<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\ValueObjects\Slug;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugPolicyException;

/**
 * Produces a lowercase Base36 slug of a fixed length through SlugPolicy.
 *
 * A candidate that lands on the denylist is discarded and regenerated under
 * its own ceiling — this budget is independent of the collision retries the
 * ReserveSlug use case performs against the database.
 */
final readonly class SlugGenerator
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(
        private RandomSlugSource $random,
        private SlugPolicy $policy,
        private int $length = 8,
        private int $maxDenylistDiscards = 5,
    ) {}

    public function generate(): Slug
    {
        $discards = 0;

        while (true) {
            $candidate = $this->random->candidate($this->length, self::ALPHABET);

            try {
                return $this->policy->fromGenerated($candidate);
            } catch (SlugPolicyException $exception) {
                if ($exception->rejectionReason() !== SlugRejectionReason::ReservedWord) {
                    throw $exception;
                }

                $discards++;

                if ($discards >= $this->maxDenylistDiscards) {
                    throw SlugGenerationExhausted::exhausted();
                }
            }
        }
    }
}
