<?php

declare(strict_types=1);

use Modules\Links\Domain\Enums\DestinationRejectionReason;

describe('DestinationRejectionReason', function () {
    it('exposes exactly the twelve stable rejection codes with matching backed values', function () {
        expect(DestinationRejectionReason::cases())->toHaveCount(12)
            ->and(DestinationRejectionReason::TooLong->value)->toBe('TOO_LONG')
            ->and(DestinationRejectionReason::NonAsciiInput->value)->toBe('NON_ASCII_INPUT')
            ->and(DestinationRejectionReason::ControlCharacter->value)->toBe('CONTROL_CHARACTER')
            ->and(DestinationRejectionReason::InvalidPercentEncoding->value)->toBe('INVALID_PERCENT_ENCODING')
            ->and(DestinationRejectionReason::MalformedUrl->value)->toBe('MALFORMED_URL')
            ->and(DestinationRejectionReason::SchemeNotAllowed->value)->toBe('SCHEME_NOT_ALLOWED')
            ->and(DestinationRejectionReason::UserinfoPresent->value)->toBe('USERINFO_PRESENT')
            ->and(DestinationRejectionReason::InvalidHostname->value)->toBe('INVALID_HOSTNAME')
            ->and(DestinationRejectionReason::IpLiteral->value)->toBe('IP_LITERAL')
            ->and(DestinationRejectionReason::SpecialUseHost->value)->toBe('SPECIAL_USE_HOST')
            ->and(DestinationRejectionReason::SelfHost->value)->toBe('SELF_HOST')
            ->and(DestinationRejectionReason::InvalidPort->value)->toBe('INVALID_PORT');
    });

    it('rejects any value outside the twelve stable codes (fixed cardinality, no user data)', function () {
        expect(fn () => DestinationRejectionReason::from('unknown'))->toThrow(ValueError::class);
    });
});
