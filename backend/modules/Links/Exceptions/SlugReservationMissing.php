<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use DomainException;

/**
 * Raised when a short link insert references a slug that has no reservation.
 *
 * The message is uniform and does not echo the slug value — callers must not
 * learn which reservation was missing from the exception surface.
 */
final class SlugReservationMissing extends DomainException
{
    public const ERROR_CODE = 'SLUG_RESERVATION_MISSING';

    private function __construct()
    {
        parent::__construct('A slug reservation is required before creating a short link.');
    }

    public static function required(): self
    {
        return new self;
    }

    public function errorCode(): string
    {
        return self::ERROR_CODE;
    }
}
