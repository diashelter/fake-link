<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class EmailAlreadyVerifiedException extends DomainException
{
    public const EMAIL_ALREADY_VERIFIED = 'EMAIL_ALREADY_VERIFIED';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function alreadyVerified(): self
    {
        return new self(
            errorCode: self::EMAIL_ALREADY_VERIFIED,
            message: 'The email address is already verified.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
