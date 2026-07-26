<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class RegistrationNotAllowedException extends DomainException
{
    public const REGISTRATION_NOT_ALLOWED = 'REGISTRATION_NOT_ALLOWED';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAllowed(): self
    {
        return new self(
            errorCode: self::REGISTRATION_NOT_ALLOWED,
            message: 'Registration is not available for these details.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
