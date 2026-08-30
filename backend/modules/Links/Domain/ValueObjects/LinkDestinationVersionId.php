<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

use Modules\Links\Exceptions\LinksDomainException;

final readonly class LinkDestinationVersionId
{
    private const UUID_V7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private function __construct(private string $value) {}

    public static function fromString(string $uuid): self
    {
        if (preg_match(self::UUID_V7_PATTERN, $uuid) !== 1) {
            throw LinksDomainException::invalidLinkDestinationVersionId($uuid);
        }

        return new self(strtolower($uuid));
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
