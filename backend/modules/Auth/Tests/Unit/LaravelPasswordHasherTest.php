<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Infrastructure\Hashing\LaravelPasswordHasher;

uses(Tests\TestCase::class);

describe('LaravelPasswordHasher', function () {
    beforeEach(function () {
        config([
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 65536,
            'hashing.argon.threads' => 1,
            'hashing.argon.time' => 4,
        ]);

        $this->hasher = new LaravelPasswordHasher;
    });

    it('hashes and verifies a password round-trip', function () {
        $plainText = 'AnotherValid1!';

        $hash = $this->hasher->hash($plainText);

        expect($this->hasher->verify($plainText, $hash))->toBeTrue();
    });

    it('does not store plaintext as the hash output', function () {
        $plainText = 'AnotherValid1!';

        expect($this->hasher->hash($plainText))->not->toBe($plainText);
    });

    it('uses the argon2id algorithm prefix', function () {
        $hash = $this->hasher->hash('AnotherValid1!');

        expect($hash)->toStartWith('$argon2id$');
    });

    it('rejects verification when plaintext does not match', function () {
        $hash = $this->hasher->hash('AnotherValid1!');

        expect($this->hasher->verify('WrongPassword1!', $hash))->toBeFalse();
    });

    it('does not expose plaintext when hash facade throws', function () {
        Hash::shouldReceive('make')
            ->once()
            ->andThrow(new RuntimeException('hashing failed'));

        $plainText = 'SecretPass123!';

        try {
            $this->hasher->hash($plainText);
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->not->toContain($plainText);

            return;
        }

        throw new RuntimeException('Expected RuntimeException was not thrown.');
    });
});
