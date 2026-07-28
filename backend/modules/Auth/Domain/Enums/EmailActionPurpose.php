<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Enums;

enum EmailActionPurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';

    public const ABSOLUTE_TTL_SECONDS = [
        'email_verification' => 3600,
        'password_reset' => 1800,
    ];

    public static function fromString(string $value): self
    {
        return self::from($value);
    }

    public function absoluteTtlSeconds(): int
    {
        return self::ABSOLUTE_TTL_SECONDS[$this->value];
    }
}
