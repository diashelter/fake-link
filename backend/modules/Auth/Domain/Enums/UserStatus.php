<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Enums;

enum UserStatus: string
{
    case PendingVerification = 'pending_verification';
    case Active = 'active';
    case Suspended = 'suspended';
    case DeletionPending = 'deletion_pending';

    public static function fromString(string $value): self
    {
        return self::from($value);
    }
}
