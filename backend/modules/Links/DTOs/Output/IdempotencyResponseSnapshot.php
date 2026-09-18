<?php

declare(strict_types=1);

namespace Modules\Links\DTOs\Output;

/**
 * Semantic 201 response captured for idempotent replay.
 *
 * X-Request-ID is intentionally excluded — it is applied per request at the HTTP edge.
 */
final readonly class IdempotencyResponseSnapshot
{
    /**
     * @param  array{Location: string, ETag: string, Cache-Control: string}  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
