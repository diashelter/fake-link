<?php

declare(strict_types=1);

use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Exceptions\LinksDomainException;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\UseCases\SealDestinationUrl;

/**
 * A DestinationCipher test double that counts encrypt() calls without doing any
 * real cryptography — used to prove SealDestinationUrl's call discipline (zero
 * invocations on rejection, one invocation per accepted call).
 */
final class CountingDestinationCipher implements DestinationCipher
{
    public int $encryptCalls = 0;

    public function encrypt(DestinationUrl $url): EncryptedDestination
    {
        $this->encryptCalls++;

        return EncryptedDestination::fromParts('stub-envelope', 'stub-key');
    }

    public function decrypt(EncryptedDestination $envelope): DestinationUrl
    {
        throw new RuntimeException('CountingDestinationCipher::decrypt() is not used by these tests.');
    }
}

function realCipher(string $activeKeyId = 'k1'): Aes256GcmDestinationCipher
{
    $keyring = DestinationKeyring::fromConfig([
        'keyring' => json_encode(['k1' => base64_encode(str_repeat("\x00", 32))]),
        'active_key_id' => $activeKeyId,
    ]);

    return new Aes256GcmDestinationCipher($keyring, new PublicHostClassifier([]));
}

describe('SealDestinationUrl — round-trip with a real cipher (LDST-21)', function () {
    it('decrypts the sealed envelope back to the normalized value, including query string and fragment', function () {
        $cipher = realCipher();
        $seal = new SealDestinationUrl(new PublicHostClassifier([]), $cipher);

        $encrypted = $seal('https://example.com/path?a=1&b=2#frag');
        $decrypted = $cipher->decrypt($encrypted);

        expect($decrypted->value())->toBe('https://example.com/path?a=1&b=2#frag');
    });

    it('normalizes before sealing, so the decrypted value reflects normalization, not the raw input', function () {
        $cipher = realCipher();
        $seal = new SealDestinationUrl(new PublicHostClassifier([]), $cipher);

        $encrypted = $seal('HTTPS://Example.COM:443/Path');
        $decrypted = $cipher->decrypt($encrypted);

        expect($decrypted->value())->toBe('https://example.com/Path');
    });

    it('returns the active_key_id from the keyring configuration', function () {
        $cipher = realCipher(activeKeyId: 'k1');
        $seal = new SealDestinationUrl(new PublicHostClassifier([]), $cipher);

        $encrypted = $seal('https://example.com/x');

        expect($encrypted->keyId())->toBe('k1');
    });
});

describe('SealDestinationUrl — rejection never reaches the cipher (LDST-22)', function () {
    it('throws the domain exception and calls encrypt() zero times for a rejected raw value', function () {
        $spy = new CountingDestinationCipher;
        $seal = new SealDestinationUrl(new PublicHostClassifier([]), $spy);

        try {
            $seal('ftp://example.com/x');
        } catch (LinksDomainException) {
            expect($spy->encryptCalls)->toBe(0);

            return;
        }

        throw new RuntimeException('Expected LinksDomainException was not thrown.');
    });

    it('calls encrypt() zero times for a self-host destination', function () {
        $spy = new CountingDestinationCipher;
        $seal = new SealDestinationUrl(new PublicHostClassifier(['go.localhost']), $spy);

        try {
            $seal('https://go.localhost/x');
        } catch (LinksDomainException) {
            expect($spy->encryptCalls)->toBe(0);

            return;
        }

        throw new RuntimeException('Expected LinksDomainException was not thrown.');
    });
});

describe('SealDestinationUrl — revalidates on every call (LDST-22)', function () {
    it('runs the policy again on a second call: a prior successful call does not exempt a later invalid one', function () {
        $spy = new CountingDestinationCipher;
        $seal = new SealDestinationUrl(new PublicHostClassifier([]), $spy);

        $seal('https://example.com/x'); // first call: accepted

        expect(fn () => $seal('ftp://example.com/x'))->toThrow(LinksDomainException::class);
        expect($spy->encryptCalls)->toBe(1); // only the first, accepted call reached the cipher
    });

    it('calls encrypt() once per accepted call — two calls for the same link (creation, then a destination swap) invoke the cipher twice', function () {
        $spy = new CountingDestinationCipher;
        $seal = new SealDestinationUrl(new PublicHostClassifier([]), $spy);

        $seal('https://example.com/first');
        $seal('https://example.com/second');

        expect($spy->encryptCalls)->toBe(2);
    });
});
