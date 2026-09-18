<?php

declare(strict_types=1);

namespace Modules\Links\UseCases;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\DestinationVersionRepository;
use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Contracts\Services\TransactionManager;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugUnavailable;

/**
 * Creates a short link: seals the destination outside the transaction, then
 * reserves the slug, inserts the link, and opens the first destination version
 * in a single transaction (owned here or joined from an outer TransactionManager).
 */
final readonly class CreateLink
{
    public function __construct(
        private SealDestinationUrl $sealDestinationUrl,
        private PublicHostClassifier $hosts,
        private ReserveSlug $reserveSlug,
        private ShortLinkRepository $shortLinks,
        private DestinationVersionRepository $destinationVersions,
        private EffectiveStatus $effectiveStatus,
        private TransactionManager $transactions,
    ) {}

    /**
     * @throws SlugUnavailable
     * @throws SlugGenerationExhausted
     */
    public function execute(UserId $ownerId, CreateLinkInput $input): CreatedLinkDto
    {
        // Destination policy + cipher run before any write or transaction.
        $encrypted = ($this->sealDestinationUrl)($input->destinationUrl);
        $normalizedDestination = DestinationUrl::fromString($input->destinationUrl, $this->hosts)->value();

        return $this->transactions->run(function () use ($ownerId, $input, $encrypted, $normalizedDestination): CreatedLinkDto {
            $slug = $input->customAlias !== null
                ? $this->reserveSlug->forAlias($input->customAlias)
                : $this->reserveSlug->automatic();

            $persisted = $this->shortLinks->create(
                ownerId: $ownerId,
                slug: $slug,
                slugSource: $slug->source(),
                title: $input->title,
                expiresAt: $input->expiresAt,
            );

            $this->destinationVersions->openFirstVersion(
                shortLinkId: $persisted->id,
                encrypted: $encrypted,
                validFrom: $persisted->createdAt,
            );

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $status = $this->effectiveStatus->for(
                blockedAt: $persisted->blockedAt,
                expiresAt: $persisted->expiresAt,
                isEnabled: $persisted->isEnabled,
                now: $now,
            );

            return new CreatedLinkDto(
                id: $persisted->id->value(),
                slug: $persisted->slug->value(),
                slugSource: $persisted->slugSource,
                destinationUrl: $normalizedDestination,
                title: $persisted->title,
                isEnabled: $persisted->isEnabled,
                status: $status,
                expiresAt: $persisted->expiresAt,
                blockedAt: $persisted->blockedAt,
                createdAt: $persisted->createdAt,
                updatedAt: $persisted->updatedAt,
                version: $persisted->version,
            );
        });
    }
}
