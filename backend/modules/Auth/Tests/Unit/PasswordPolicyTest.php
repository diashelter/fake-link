<?php

declare(strict_types=1);

use Modules\Auth\Domain\Enums\PasswordViolationCode;
use Modules\Auth\Domain\Services\PasswordPolicy;
use Modules\Auth\Exceptions\PasswordPolicyException;

describe('PasswordPolicy', function () {
    beforeEach(function () {
        $this->policy = new PasswordPolicy;
    });

    it('accepts a password with exactly 12 characters when complexity is satisfied', function () {
        expect(fn () => $this->policy->validate('ValidPass1!x'))->not->toThrow(PasswordPolicyException::class);
    });

    it('accepts a password with exactly 128 characters when complexity is satisfied', function () {
        $password = str_repeat('Aa1!', 32);

        expect(fn () => $this->policy->validate($password))->not->toThrow(PasswordPolicyException::class);
    });

    it('rejects passwords shorter than 12 characters', function () {
        try {
            $this->policy->validate('Short1!a');
        } catch (PasswordPolicyException $exception) {
            expect($exception->violationCode())->toBe(PasswordViolationCode::TooShort)
                ->and($exception->getMessage())->not->toContain('Short1!a');

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });

    it('rejects passwords longer than 128 characters', function () {
        $password = str_repeat('Aa1!', 33);

        try {
            $this->policy->validate($password);
        } catch (PasswordPolicyException $exception) {
            expect($exception->violationCode())->toBe(PasswordViolationCode::TooLong)
                ->and($exception->getMessage())->not->toContain($password);

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });

    it('rejects missing lowercase letters', function () {
        try {
            $this->policy->validate('VALIDPASS1!X');
        } catch (PasswordPolicyException $exception) {
            expect($exception->violationCode())->toBe(PasswordViolationCode::MissingLowercase);

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });

    it('rejects missing uppercase letters', function () {
        try {
            $this->policy->validate('validpass1!x');
        } catch (PasswordPolicyException $exception) {
            expect($exception->violationCode())->toBe(PasswordViolationCode::MissingUppercase);

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });

    it('rejects missing digits', function () {
        try {
            $this->policy->validate('ValidPass!!!');
        } catch (PasswordPolicyException $exception) {
            expect($exception->violationCode())->toBe(PasswordViolationCode::MissingDigit);

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });

    it('rejects missing ascii symbols', function () {
        try {
            $this->policy->validate('ValidPass123');
        } catch (PasswordPolicyException $exception) {
            expect($exception->violationCode())->toBe(PasswordViolationCode::MissingSymbol);

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });

    it('reports stable violation codes from violations()', function () {
        expect($this->policy->violations('short'))
            ->toContain(PasswordViolationCode::TooShort)
            ->and($this->policy->violations('short')[0]->value)->toBe('too_short');
    });

    it('treats unicode characters outside ascii as failing ascii categories', function () {
        // SPEC: only ASCII counts toward lowercase/uppercase/digit/symbol categories.
        expect($this->policy->violations('café1234567!'))
            ->toContain(PasswordViolationCode::MissingUppercase);
    });

    it('accepts printable ascii symbols outside letters and digits', function () {
        expect(fn () => $this->policy->validate('ValidPass1@x'))->not->toThrow(PasswordPolicyException::class);
    });

    it('returns an empty violation list for a valid password', function () {
        expect($this->policy->violations('AnotherValid1!'))->toBe([]);
    });

    it('does not include plaintext in exception messages', function () {
        $password = 'AnotherValid1!';

        try {
            $this->policy->validate('AnotherValid');
        } catch (PasswordPolicyException $exception) {
            expect($exception->getMessage())->not->toContain($password);

            return;
        }

        throw new RuntimeException('Expected PasswordPolicyException was not thrown.');
    });
});
