<?php

declare(strict_types=1);

namespace Modules\Links\UseCases;

use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\LinkQueryRepository;
use Modules\Links\Contracts\Services\Clock;
use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Services\EffectiveStatus;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\Output\GetLinkResult;
use Modules\Links\DTOs\Output\LinkDetailDto;
use Modules\Links\Exceptions\DestinationDecryptionFailed;

final readonly class GetLink
{
    public function __construct(
        private LinkQueryRepository $queries,
        private DestinationCipher $cipher,
        private EffectiveStatus $effectiveStatus,
        private LinkETag $etag,
        private Clock $clock,
    ) {}

    /**
     * @throws DestinationDecryptionFailed
     */
    public function execute(UserId $ownerId, ShortLinkId $linkId): ?GetLinkResult
    {
        $row = $this->queries->findForOwner($ownerId, $linkId);

        if ($row === null) {
            return null;
        }

        $destination = $this->cipher->decrypt($row->destination);

        $status = $this->effectiveStatus->for(
            blockedAt: $row->blockedAt,
            expiresAt: $row->expiresAt,
            isEnabled: $row->isEnabled,
            now: $this->clock->now(),
        );

        $link = new LinkDetailDto(
            id: $row->id->value(),
            slug: $row->slug->value(),
            slugSource: $row->slugSource,
            destinationUrl: $destination->value(),
            title: $row->title,
            isEnabled: $row->isEnabled,
            status: $status,
            expiresAt: $row->expiresAt,
            createdAt: $row->createdAt,
            updatedAt: $row->updatedAt,
        );

        return new GetLinkResult(
            link: $link,
            etag: $this->etag->for(
                id: $link->id,
                slug: $link->slug,
                normalizedDestinationUrl: $link->destinationUrl,
                title: $link->title,
                isEnabled: $link->isEnabled,
                expiresAt: $link->expiresAt,
                blockedAt: $row->blockedAt,
                updatedAt: $link->updatedAt,
                effectiveStatus: $link->status,
            ),
        );
    }
}
