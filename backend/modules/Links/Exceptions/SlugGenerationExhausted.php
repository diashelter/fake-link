<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;

/**
 * Raised when automatic slug generation gives up after exhausting a retry
 * budget (collision INSERTs or denylist discards).
 *
 * The message reveals neither how many attempts were made nor any candidate
 * slug that was tried. The stable error code is mapped to 503 by the
 * link-creation slice.
 */
final class SlugGenerationExhausted extends DomainException
{
    public const ERROR_CODE = 'SLUG_GENERATION_FAILED';

    private function __construct()
    {
        parent::__construct('Slug generation failed: no available slug could be reserved.');
    }

    public static function exhausted(): self
    {
        return new self;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
