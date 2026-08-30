<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

use Modules\Links\Exceptions\LinksDomainException;

final readonly class Slug
{
    private const PATTERN = '/^[a-z0-9-]{1,48}$/';

    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        if (preg_match(self::PATTERN, $raw) !== 1) {
            throw LinksDomainException::invalidSlug();
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
