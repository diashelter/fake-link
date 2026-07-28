<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class PasswordReusedException extends DomainException
{
    public const PASSWORD_REUSED = 'PASSWORD_REUSED';

    public const MESSAGE = 'The new password must be different from the current password.';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function reused(): self
    {
        return new self(
            errorCode: self::PASSWORD_REUSED,
            message: self::MESSAGE,
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
