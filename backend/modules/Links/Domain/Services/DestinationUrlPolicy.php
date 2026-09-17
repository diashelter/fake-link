<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use League\Uri\Exceptions\SyntaxError;
use League\Uri\Uri;
use Modules\Links\Domain\Enums\DestinationRejectionReason;
use Modules\Links\Exceptions\LinksDomainException;

final class DestinationUrlPolicy
{
    private const MAX_LENGTH = 2048;

    /**
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(private readonly PublicHostClassifier $hosts) {}

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

    /**
     * Throws the domain exception for a rejection reason, after neutralizing PHP's own
     * exception-trace capture for the rest of this request (LDST-24).
     *
     * PHP's Exception::getTrace()/getTraceAsString() record the *current* value of every
     * live local variable passed as an argument to every function still on the call stack
     * at the moment the exception is constructed — not just this method's. Verified
     * empirically: without this, the raw destination URL (still sitting as the $raw
     * parameter of evaluate(), normalize(), DestinationUrl::fromString(), and every caller
     * above it) would appear in full in getTrace(), and as a partial prefix in
     * getTraceAsString(), for every single rejection reason — not only the parser's own
     * SyntaxError case that step 5 already guards against. ini_set('zend.exception_ignore_args')
     * is per-request (PHP-FPM resets it for the next request) and, called here before the
     * exception is constructed, suppresses argument capture for every frame on the current
     * stack at once — the one point that can close this for every present and future caller
     * of the policy, without relying on each of them to remember to redact their own copy.
     */
    private function fail(DestinationRejectionReason $reason): never
    {
        ini_set('zend.exception_ignore_args', '1');

        throw LinksDomainException::invalidDestinationUrl($reason);
    }

    private function evaluate(string $raw): string
    {
        // Step 1: trim border whitespace only — internal whitespace is a control character (step 3).
        $raw = trim($raw, " \t\r\n\0\x0B");

        // Step 2: raw length, after trim, before any parsing.
        if (strlen($raw) > self::MAX_LENGTH) {
            $this->fail(DestinationRejectionReason::TooLong);
        }

        // Step 3: any byte outside printable ASCII (0x20-0x7E) is either a control character
        // (0x00-0x1F, 0x7F) or non-ASCII (>= 0x80) — classified by the first offending byte found.
        if (preg_match('/[^\x20-\x7E]/', $raw, $match) === 1) {
            $this->fail(
                ord($match[0]) >= 0x80
                    ? DestinationRejectionReason::NonAsciiInput
                    : DestinationRejectionReason::ControlCharacter,
            );
        }

        // Step 4: percent-encoding must be well-formed before the value ever reaches a URL parser,
        // which would otherwise silently rewrite a malformed "%" sequence (e.g. "%" -> "%25").
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            $this->fail(DestinationRejectionReason::InvalidPercentEncoding);
        }

        // Step 5: parse via the URL parser only — never by concatenation or textual search.
        // The SyntaxError message contains the raw URL, so it is deliberately discarded here:
        // never chained as $previous, never rethrown, never logged.
        //
        // SPEC_DEVIATION: spec.md's edge-case table pairs "https://example.com:-1/x" with
        // INVALID_PORT, and AC11 names a non-numeric port as an INVALID_PORT case. Verified
        // empirically (league/uri 7.8.1): RFC 3986 defines port as *DIGIT, so Uri::new() itself
        // throws SyntaxError for ANY non-digit port content (a leading "-", letters, "+", a
        // decimal point) before step 9's range check can ever run — there is no reachable code
        // path, short of textually pre-parsing the authority ourselves (forbidden by LDST-07/
        // AD-019), that turns a syntactically-invalid port into InvalidPort. Only a syntactically
        // valid all-digit port that is out of the 1-65535 range (e.g. "0", "65536") reaches step
        // 9. Non-digit/negative ports are classified MalformedUrl instead; the public contract
        // (422 INVALID_DESTINATION_URL) is unaffected either way.
        try {
            $uri = Uri::new($raw);
        } catch (SyntaxError) {
            $this->fail(DestinationRejectionReason::MalformedUrl);
        }

        // Step 6: scheme.
        if (! in_array($uri->getScheme(), self::ALLOWED_SCHEMES, true)) {
            $this->fail(DestinationRejectionReason::SchemeNotAllowed);
        }

        // Step 7: userinfo — league/uri returns '', ':', 'u', 'u:p', never null, for any of the
        // userinfo forms, so a plain identity check against null covers all of them.
        if ($uri->getUserInfo() !== null) {
            $this->fail(DestinationRejectionReason::UserinfoPresent);
        }

        // Step 8: host — trailing FQDN dot is trimmed before classification (the classifier
        // operates on an already-normalized host), then delegated to PublicHostClassifier.
        $host = $uri->getHost();

        if ($host === null || $host === '') {
            $this->fail(DestinationRejectionReason::MalformedUrl);
        }

        $host = rtrim($host, '.');

        $hostRejection = $this->hosts->reject($host);

        if ($hostRejection !== null) {
            $this->fail($hostRejection);
        }

        // Step 9: port range. league/uri already normalizes away the scheme's default port
        // (and any redundant leading zeros that resolve to it), so a non-null value here is
        // always a genuinely custom port.
        $port = $uri->getPort();

        if ($port !== null && ($port < 1 || $port > 65535)) {
            $this->fail(DestinationRejectionReason::InvalidPort);
        }

        // Step 10: reconstruct the canonical value. withHost() applies the trailing-dot-trimmed
        // host computed in step 8 (scheme and host case, and the default port, are already
        // canonical courtesy of the parser). Path, query, fragment and percent-encoding are left
        // untouched — only an empty path is rewritten to "/". This is the only mutation applied;
        // toString() is idempotent when re-parsed, which is exercised by the property test below.
        $normalizedUri = $uri->withHost($host);

        if ($normalizedUri->getPath() === '') {
            $normalizedUri = $normalizedUri->withPath('/');
        }

        $normalized = $normalizedUri->toString();

        // Step 11: the normalization step only ever shortens or preserves length (it never adds
        // characters beyond a single "/"), but the contract of link_destination_versions.
        // destination_url requires the persisted, normalized value to itself be <=2048.
        if (strlen($normalized) > self::MAX_LENGTH) {
            $this->fail(DestinationRejectionReason::TooLong);
        }

        return $normalized;
    }
}
