<?php

declare(strict_types=1);

namespace Modules\Auth\Domain\Services;

use Modules\Auth\Domain\Enums\PasswordViolationCode;
use Modules\Auth\Exceptions\PasswordPolicyException;

final class PasswordPolicy
{
    private const MIN_LENGTH = 12;

    private const MAX_LENGTH = 128;

    /**
     * @return list<PasswordViolationCode>
     */
    public function violations(string $plainText): array
    {
        $violations = [];

        $length = strlen($plainText);

        if ($length < self::MIN_LENGTH) {
            $violations[] = PasswordViolationCode::TooShort;
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = PasswordViolationCode::TooLong;
        }

        if (! preg_match('/[a-z]/', $plainText)) {
            $violations[] = PasswordViolationCode::MissingLowercase;
        }

        if (! preg_match('/[A-Z]/', $plainText)) {
            $violations[] = PasswordViolationCode::MissingUppercase;
        }

        if (! preg_match('/[0-9]/', $plainText)) {
            $violations[] = PasswordViolationCode::MissingDigit;
        }

        if (! preg_match('/[!-\/:-@\[-`{-~]/', $plainText)) {
            $violations[] = PasswordViolationCode::MissingSymbol;
        }

        return $violations;
    }

    public function validate(string $plainText): void
    {
        $violations = $this->violations($plainText);

        if ($violations === []) {
            return;
        }

        throw PasswordPolicyException::fromViolation($violations[0]);
    }
}
