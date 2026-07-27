<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class InviteAllowlistUnavailableException extends DomainException
{
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(
            errorCode: self::SERVICE_UNAVAILABLE,
            message: 'The service is temporarily unavailable.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
