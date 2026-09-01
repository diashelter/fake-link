<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;

/**
 * Raised when a slug cannot be reserved because it is already taken.
 *
 * The message is uniform and carries no information about the occupying
 * reservation — no owner, no reserved_at, no title, no destination, and no
 * distinction between an orphan reservation and one backed by a live link.
 * Any such data would let a caller enumerate the global slug namespace.
 */
final class SlugUnavailable extends DomainException
{
    public const ERROR_CODE = 'ALIAS_UNAVAILABLE';

    private function __construct()
    {
        parent::__construct('The requested slug is unavailable.');
    }

    public static function reserved(): self
    {
        return new self;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
