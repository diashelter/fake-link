<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Exceptions\LinksDomainException;

final class DestinationUrlPolicy
{
    private const MAX_LENGTH = 2048;

    /**
     * Run the full policy chain and return the normalized value.
     *
     * @throws LinksDomainException when any rule in the chain rejects the input
     */
    public function normalize(string $raw): string
    {
        return $this->evaluate($raw);
    }

    /**
     * Non-throwing variant of the chain, used by table-driven tests.
     */
    public function reject(string $raw): ?DestinationRejectionReason
    {
        try {
            $this->evaluate($raw);

            return null;
        } catch (LinksDomainException $e) {
            return $e->reason();
        }
    }

    private function evaluate(string $raw): string
    {
        // Step 1: trim border whitespace only — internal whitespace is a control character (step 3).
        $raw = trim($raw, " \t\r\n\0\x0B");

        // Step 2: raw length, after trim, before any parsing.
        if (strlen($raw) > self::MAX_LENGTH) {
            throw LinksDomainException::invalidDestinationUrl(DestinationRejectionReason::TooLong);
        }

        // Step 3: any byte outside printable ASCII (0x20-0x7E) is either a control character
        // (0x00-0x1F, 0x7F) or non-ASCII (>= 0x80) — classified by the first offending byte found.
        if (preg_match('/[^\x20-\x7E]/', $raw, $match) === 1) {
            throw LinksDomainException::invalidDestinationUrl(
                ord($match[0]) >= 0x80
                    ? DestinationRejectionReason::NonAsciiInput
                    : DestinationRejectionReason::ControlCharacter,
            );
        }

        // Step 4: percent-encoding must be well-formed before the value ever reaches a URL parser,
        // which would otherwise silently rewrite a malformed "%" sequence (e.g. "%" -> "%25").
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            throw LinksDomainException::invalidDestinationUrl(DestinationRejectionReason::InvalidPercentEncoding);
        }

        return $raw;
    }
}
