<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\ValueObjects;

use Modules\Auth\Exceptions\AuthDomainException;

final readonly class EmailAddress
{
    private function __construct(private string $value) {}

    public static function fromString(string $raw): self
    {
        $normalized = strtolower(trim($raw));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw AuthDomainException::invalidEmail($raw);
        }

        return new self($normalized);
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
