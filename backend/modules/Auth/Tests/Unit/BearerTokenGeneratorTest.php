<?php

declare(strict_types=1);

use Modules\Auth\Domain\Services\BearerTokenGenerator;

describe('BearerTokenGenerator', function () {
    it('generates base64url plaintext of approximately 43 characters', function () {
        $generator = new BearerTokenGenerator;
        $plainText = $generator->generatePlainText();

        expect($plainText)->toMatch('/^[A-Za-z0-9_-]+$/')
            ->and(strlen($plainText))->toBeGreaterThanOrEqual(43)
            ->and(strlen($plainText))->toBeLessThanOrEqual(44);
    });

    it('generates distinct plaintext values on successive calls', function () {
        $generator = new BearerTokenGenerator;

        expect($generator->generatePlainText())->not->toBe($generator->generatePlainText());
    });
});
