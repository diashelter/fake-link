<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Modules\Auth\Infrastructure\Http\Rules\PasswordPolicyRule;
use Tests\TestCase;

uses(TestCase::class);

describe('PasswordPolicyRule', function () {
    it('rejects passwords shorter than 12 characters', function () {
        $validator = Validator::make(
            ['password' => 'Short1!a'],
            ['password' => [new PasswordPolicyRule]],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->has('password'))->toBeTrue();
    });

    it('rejects passwords missing an ascii symbol', function () {
        $validator = Validator::make(
            ['password' => 'ValidPass123'],
            ['password' => [new PasswordPolicyRule]],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->has('password'))->toBeTrue();
    });

    it('accepts a password that satisfies length and ascii composition', function () {
        $validator = Validator::make(
            ['password' => 'ValidPass1!x'],
            ['password' => [new PasswordPolicyRule]],
        );

        expect($validator->passes())->toBeTrue();
    });
});
