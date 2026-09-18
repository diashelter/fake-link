<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;

/**
 * Raised when an Idempotency-Key is reused with a different canonical command.
 * Maps to HTTP 409 IDEMPOTENCY_KEY_REUSED.
 */
final class IdempotencyKeyReused extends DomainException
{
    public const ERROR_CODE = 'IDEMPOTENCY_KEY_REUSED';

    private function __construct()
    {
        parent::__construct('The Idempotency-Key was already used with a different request.');
    }

    public static function reused(): self
    {
        return new self;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
