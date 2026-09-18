<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Enums;

enum DestinationRejectionReason: string
{
    case TooLong = 'TOO_LONG';
    case NonAsciiInput = 'NON_ASCII_INPUT';
    case ControlCharacter = 'CONTROL_CHARACTER';
    case InvalidPercentEncoding = 'INVALID_PERCENT_ENCODING';
    case MalformedUrl = 'MALFORMED_URL';
    case SchemeNotAllowed = 'SCHEME_NOT_ALLOWED';
    case UserinfoPresent = 'USERINFO_PRESENT';
    case InvalidHostname = 'INVALID_HOSTNAME';
    case IpLiteral = 'IP_LITERAL';
    case SpecialUseHost = 'SPECIAL_USE_HOST';
    case SelfHost = 'SELF_HOST';
    case InvalidPort = 'INVALID_PORT';
}
