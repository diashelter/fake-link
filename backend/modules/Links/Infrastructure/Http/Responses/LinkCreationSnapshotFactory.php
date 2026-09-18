<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Responses;

use JsonException;
use Modules\Links\Domain\Services\LinkETag;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;
use Modules\Links\Infrastructure\Http\Resources\LinkDetailResource;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the semantic 201 snapshot stored under an idempotency key.
 * X-Request-ID is excluded — applied per request at the HTTP edge (T5/T6).
 */
final class LinkCreationSnapshotFactory
{
    public function __construct(
        private readonly LinkETag $linkETag,
    ) {}

    public function fromCreated(CreatedLinkDto $link): IdempotencyResponseSnapshot
    {
        $etag = $this->linkETag->for(
            id: $link->id,
            slug: $link->slug,
            normalizedDestinationUrl: $link->destinationUrl,
            title: $link->title,
            isEnabled: $link->isEnabled,
            expiresAt: $link->expiresAt,
            blockedAt: $link->blockedAt,
            updatedAt: $link->updatedAt,
            effectiveStatus: $link->status,
        );

        try {
            $body = json_encode(
                ['data' => LinkDetailResource::toArray($link)],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode create-link response body.', 0, $exception);
        }

        return new IdempotencyResponseSnapshot(
            status: Response::HTTP_CREATED,
            headers: [
                'Location' => '/api/v1/links/'.$link->id,
                'ETag' => $etag,
                'Cache-Control' => 'private, no-store',
            ],
            body: $body,
        );
    }
}
