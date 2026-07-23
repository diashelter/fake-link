<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;

final class AuthDomainException extends DomainException
{
    public const INVALID_EMAIL = 'INVALID_EMAIL';

    public const INVALID_USER_ID = 'INVALID_USER_ID';

    public const EMAIL_ALREADY_IN_USE = 'EMAIL_ALREADY_IN_USE';

    public const INVALID_AUTH_TOKEN_ID = 'INVALID_AUTH_TOKEN_ID';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidEmail(string $raw): self
    {
        return new self(
            errorCode: self::INVALID_EMAIL,
            message: 'The provided email address is invalid.',
        );
    }

    public static function invalidUserId(string $raw): self
    {
        return new self(
            errorCode: self::INVALID_USER_ID,
            message: 'The provided user identifier is invalid.',
        );
    }

    public static function emailAlreadyInUse(): self
    {
        return new self(
            errorCode: self::EMAIL_ALREADY_IN_USE,
            message: 'The email address is already in use.',
        );
    }

    public static function invalidAuthTokenId(string $raw): self
    {
        return new self(
            errorCode: self::INVALID_AUTH_TOKEN_ID,
            message: 'The provided auth token identifier is invalid.',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
