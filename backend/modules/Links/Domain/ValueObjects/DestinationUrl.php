<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

use Modules\Links\Exceptions\LinksDomainException;

final readonly class DestinationUrl
{
    private const MAX_LENGTH = 2048;

    private const ALLOWED_SCHEMES = ['http', 'https'];

    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        if (strlen($raw) > self::MAX_LENGTH) {
            throw LinksDomainException::invalidDestinationUrl();
        }

        $parsed = parse_url($raw);

        if ($parsed === false) {
            throw LinksDomainException::invalidDestinationUrl();
        }

        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw LinksDomainException::invalidDestinationUrl();
        }

        $host = $parsed['host'] ?? '';

        if ($host === '') {
            throw LinksDomainException::invalidDestinationUrl();
        }

        return new self($raw);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
