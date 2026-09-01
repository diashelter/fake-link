<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Enums;

enum SlugRejectionReason: string
{
    case TooShort = 'too_short';
    case TooLong = 'too_long';
    case InvalidCharacters = 'invalid_characters';
    case InvalidBoundary = 'invalid_boundary';
    case ConsecutiveHyphens = 'consecutive_hyphens';
    case ReservedWord = 'reserved_word';
}
