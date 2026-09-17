<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use Modules\Links\Domain\Enums\DestinationRejectionReason;

final class PublicHostClassifier
{
    private const MAX_HOST_LENGTH = 253;

    private const LABEL_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    private const TLD_PATTERN = '/^[a-z]{2,}$/';

    /**
     * @var list<string>
     */
    private const SPECIAL_USE_SUFFIXES = [
        'localhost',
        'local',
        'internal',
        'home.arpa',
        'test',
        'invalid',
        'example',
        'onion',
        'alt',
    ];

    /**
     * @param  list<string>  $selfHosts
     */
    public function __construct(private readonly array $selfHosts) {}

    public function reject(string $host): ?DestinationRejectionReason
    {
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return DestinationRejectionReason::IpLiteral;
        }

        $normalized = strtolower($host);

        if (strlen($normalized) > self::MAX_HOST_LENGTH) {
            return DestinationRejectionReason::InvalidHostname;
        }

        $labels = explode('.', $normalized);

        if (count($labels) < 2) {
            return DestinationRejectionReason::InvalidHostname;
        }

        foreach ($labels as $label) {
            if (! preg_match(self::LABEL_PATTERN, $label)) {
                return DestinationRejectionReason::InvalidHostname;
            }
        }

        if (! preg_match(self::TLD_PATTERN, $labels[array_key_last($labels)])) {
            return DestinationRejectionReason::InvalidHostname;
        }

        foreach ($this->selfHosts as $selfHost) {
            $normalizedSelfHost = strtolower($selfHost);

            if ($normalized === $normalizedSelfHost || str_ends_with($normalized, '.'.$normalizedSelfHost)) {
                return DestinationRejectionReason::SelfHost;
            }
        }

        foreach (self::SPECIAL_USE_SUFFIXES as $suffix) {
            if ($normalized === $suffix || str_ends_with($normalized, '.'.$suffix)) {
                return DestinationRejectionReason::SpecialUseHost;
            }
        }

        return null;
    }
}
