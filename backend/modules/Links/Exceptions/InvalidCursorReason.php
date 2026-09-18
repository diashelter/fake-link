<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

enum InvalidCursorReason: string
{
    case Malformed = 'malformed';
    case InvalidSignature = 'invalid_signature';
    case InvalidTypes = 'invalid_types';
    case UnsupportedVersion = 'unsupported_version';
    case ScopeMismatch = 'scope_mismatch';
}
