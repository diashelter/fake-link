<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Enums;

enum TokenKind: string
{
    case Verification = 'verification';
    case Session = 'session';

    public const ABSOLUTE_TTL_SECONDS = [
        'verification' => 86400,
        'session' => 604800,
    ];

    public const IDLE_TTL_SECONDS = [
        'verification' => 3600,
        'session' => 86400,
    ];

    public static function fromString(string $value): self
    {
        return self::from($value);
    }

    public function absoluteTtlSeconds(): int
    {
        return self::ABSOLUTE_TTL_SECONDS[$this->value];
    }

    public function idleTtlSeconds(): int
    {
        return self::IDLE_TTL_SECONDS[$this->value];
    }
}
