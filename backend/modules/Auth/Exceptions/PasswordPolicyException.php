<?php

declare(strict_types=1);

namespace Modules\Auth\Exceptions;

use DomainException;
use Modules\Auth\Domain\Enums\PasswordViolationCode;

final class PasswordPolicyException extends DomainException
{
    private function __construct(
        private readonly PasswordViolationCode $violationCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromViolation(PasswordViolationCode $violationCode): self
    {
        return new self(
            violationCode: $violationCode,
            message: sprintf('Password policy violation: %s.', $violationCode->value),
        );
    }

    public function violationCode(): PasswordViolationCode
    {
        return $this->violationCode;
    }
}
