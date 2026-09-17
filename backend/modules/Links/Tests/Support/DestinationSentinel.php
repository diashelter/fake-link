<?php

declare(strict_types=1);

namespace Modules\Links\Tests\Support;

/**
 * Builds destination URLs carrying a unique, per-call token planted in as many of
 * host/path/query/fragment as each scenario's syntax allows, for the destination-policy
 * non-leakage gate (LDST-24). The token is pure lowercase alphanumeric, so it is valid
 * wherever it is planted (a hostname label, a path segment, a query value, a fragment).
 */
final class DestinationSentinel
{
    /**
     * A fresh, sufficiently unique token. Each call returns a different value so tests
     * never share a token and cannot accidentally pass because an earlier test's token
     * lingered in a shared log buffer.
     */
    public static function token(): string
    {
        return 'sentineldst'.bin2hex(random_bytes(10));
    }

    /**
     * An accepted, publicly-routable URL with the token planted in the host (as a
     * subdomain), the path, the query and the fragment.
     */
    public static function acceptedUrl(string $token): string
    {
        return "https://{$token}.example.com/{$token}?q={$token}#{$token}";
    }

    /**
     * One raw URL per DestinationRejectionReason, each carrying the token in whichever
     * component the rejection rule leaves room for (rejection is a pre-parse or early
     * parse-stage failure for several reasons, so the token cannot always sit in a
     * "path"/"query"/"fragment" in the parsed sense — it is placed directly in the raw
     * string wherever it is possible to keep the rejection reason exactly as named).
     *
     * @return array<string, string> DestinationRejectionReason case name => raw URL
     */
    public static function rejectionUrls(string $token, string $selfHost): array
    {
        return [
            'TooLong' => 'https://example.com/'.$token.str_repeat('a', 2100),
            'NonAsciiInput' => "https://example.com/{$token}-café",
            'ControlCharacter' => "https://example.com/{$token}-\x01-end",
            'InvalidPercentEncoding' => "https://example.com/{$token}-%zz",
            'MalformedUrl' => "https://ho st.com/{$token}",
            'SchemeNotAllowed' => "ftp://example.com/{$token}",
            'UserinfoPresent' => "https://u:p@example.com/{$token}",
            'IpLiteral' => "https://127.0.0.1/{$token}",
            'SpecialUseHost' => "https://a.localhost/{$token}",
            'SelfHost' => "https://{$selfHost}/{$token}",
            'InvalidHostname' => "https://intranet/{$token}",
            'InvalidPort' => "https://example.com:65536/{$token}",
        ];
    }
}
