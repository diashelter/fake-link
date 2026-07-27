<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Auth\Domain\Services\PasswordPolicy;

final class PasswordPolicyRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $violations = (new PasswordPolicy)->violations($value);

        if ($violations === []) {
            return;
        }

        $fail(sprintf('The :attribute does not meet the password policy (%s).', $violations[0]->value));
    }
}
