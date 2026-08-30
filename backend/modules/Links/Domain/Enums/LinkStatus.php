<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Enums;

enum LinkStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Expired = 'expired';
    case Blocked = 'blocked';

    public static function fromString(string $value): self
    {
        return self::from($value);
    }
}
