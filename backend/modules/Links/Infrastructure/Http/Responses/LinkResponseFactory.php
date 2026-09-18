<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Http\Responses;

use Illuminate\Http\JsonResponse;
use Modules\Links\DTOs\Output\CreatedLinkDto;
use Modules\Links\Infrastructure\Http\Resources\LinkDetailResource;
use Symfony\Component\HttpFoundation\Response;

final class LinkResponseFactory
{
    public function created(CreatedLinkDto $link, string $etag, ?string $requestId = null): JsonResponse
    {
        $resolvedRequestId = $requestId ?? 'stub-request-id';

        return response()->json([
            'data' => LinkDetailResource::toArray($link),
        ], Response::HTTP_CREATED)->withHeaders([
            'Location' => '/api/v1/links/'.$link->id,
            'ETag' => $etag,
            'Cache-Control' => 'private, no-store',
            'X-Request-ID' => $resolvedRequestId,
        ]);
    }
}
