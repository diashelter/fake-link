<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class InvalidPasswordResetTokenException extends DomainException
{
    public const MESSAGE = 'The password reset token is invalid or has expired.';

    public static function invalid(): self
    {
        return new self(self::MESSAGE);
    }
}
