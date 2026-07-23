<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class AuthTokenException extends DomainException
{
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const ACCOUNT_SUSPENDED = 'ACCOUNT_SUSPENDED';

    public const ACCOUNT_PENDING_DELETION = 'ACCOUNT_PENDING_DELETION';

    public const TOKEN_RESTRICTED = 'TOKEN_RESTRICTED';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(): self
    {
        return new self(
            errorCode: self::UNAUTHENTICATED,
            message: 'Authentication is required.',
        );
    }

    public static function accountSuspended(): self
    {
        return new self(
            errorCode: self::ACCOUNT_SUSPENDED,
            message: 'The account is suspended.',
        );
    }

    public static function accountPendingDeletion(): self
    {
        return new self(
            errorCode: self::ACCOUNT_PENDING_DELETION,
            message: 'The account is pending deletion.',
        );
    }

    public static function tokenRestricted(): self
    {
        return new self(
            errorCode: self::TOKEN_RESTRICTED,
            message: 'This token cannot perform the requested operation.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
