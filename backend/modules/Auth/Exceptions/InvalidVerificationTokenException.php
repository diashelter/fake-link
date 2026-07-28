<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class InvalidVerificationTokenException extends DomainException
{
    public const INVALID_VERIFICATION_TOKEN = 'INVALID_VERIFICATION_TOKEN';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self(
            errorCode: self::INVALID_VERIFICATION_TOKEN,
            message: 'The verification token is invalid or has expired.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
