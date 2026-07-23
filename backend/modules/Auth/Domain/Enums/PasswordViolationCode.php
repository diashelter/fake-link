<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Enums;

enum PasswordViolationCode: string
{
    case TooShort = 'too_short';
    case TooLong = 'too_long';
    case MissingLowercase = 'missing_lowercase';
    case MissingUppercase = 'missing_uppercase';
    case MissingDigit = 'missing_digit';
    case MissingSymbol = 'missing_symbol';
}
