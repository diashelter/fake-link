<?php

declare(strict_types=1);

use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;

describe('Sha256TokenHasher', function () {
    it('produces a 64 character lowercase hex hash', function () {
        $hasher = new Sha256TokenHasher;
        $plainText = 'test-token-value';
        $hash = $hasher->hash($plainText);

        expect($hash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($hash)->not->toBe($plainText);
    });

    it('verifies matching plaintext and hash', function () {
        $hasher = new Sha256TokenHasher;
        $plainText = 'another-token-value';
        $hash = $hasher->hash($plainText);

        expect($hasher->verify($plainText, $hash))->toBeTrue();
    });

    it('rejects verification when plaintext does not match hash', function () {
        $hasher = new Sha256TokenHasher;
        $hash = $hasher->hash('original-token');

        expect($hasher->verify('different-token', $hash))->toBeFalse();
    });
});
