<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class InvalidCredentialsException extends DomainException
{
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self(
            errorCode: self::INVALID_CREDENTIALS,
            message: 'The provided credentials are invalid.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
