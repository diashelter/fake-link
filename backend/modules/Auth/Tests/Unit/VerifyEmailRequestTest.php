<?php

declare(strict_types=1);

use Modules\Auth\Infrastructure\Http\Requests\VerifyEmailRequest;

describe('VerifyEmailRequest', function () {
    it('declares not_regex rejection for whitespace-only tokens', function () {
        $tokenRules = (new VerifyEmailRequest)->rules()['token'];

        // Laravel skips non-implicit rules (including not_regex) when the attribute is blank,
        // so `required` owns the HTTP 422 for `" "`. This assertion kills removal of the
        // defense-in-depth `not_regex:/^\s+$/` clause that Feature middleware cannot exercise.
        expect($tokenRules)->toContain('not_regex:/^\s+$/');
    });
});
