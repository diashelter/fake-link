<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;

/**
 * Single HTTP adapter for create-link 201 responses — fresh or replayed.
 *
 * Semantic headers and body bytes come from {@see LinkCreationSnapshotFactory}.
 * X-Request-ID is applied per request and never stored in the snapshot.
 */
final class LinkResponseFactory
{
    public function __construct(
        private readonly LinkCreationSnapshotFactory $snapshots,
    ) {}

    public function created(CreatedLinkDto $link, ?string $requestId = null): JsonResponse
    {
        return $this->fromSnapshot($this->snapshots->fromCreated($link), $requestId);
    }

    public function fromSnapshot(IdempotencyResponseSnapshot $snapshot, ?string $requestId = null): JsonResponse
    {
        $resolvedRequestId = $requestId ?? 'stub-request-id';

        return JsonResponse::fromJsonString(
            $snapshot->body,
            $snapshot->status,
            [
                'Location' => $snapshot->headers['Location'],
                'ETag' => $snapshot->headers['ETag'],
                'Cache-Control' => $snapshot->headers['Cache-Control'],
                'X-Request-ID' => $resolvedRequestId,
            ],
        );
    }
}
