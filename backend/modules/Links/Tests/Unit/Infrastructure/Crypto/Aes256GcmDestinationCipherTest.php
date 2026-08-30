<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Exceptions\DestinationDecryptionFailed;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Tests\TestCase;

uses(TestCase::class);

// Two different 32-byte test keys, base64-encoded. These are obviously fake.
// Key A: all 0x00 bytes
// Key B: all 0x01 bytes
$keyA = base64_encode(str_repeat("\x00", 32));
$keyB = base64_encode(str_repeat("\x01", 32));

function makeCipher(array $keys, string $activeKeyId): Aes256GcmDestinationCipher
{
    $keyring = DestinationKeyring::fromConfig([
        'keyring' => json_encode($keys),
        'active_key_id' => $activeKeyId,
    ]);

    return new Aes256GcmDestinationCipher($keyring);
}

describe('Aes256GcmDestinationCipher', function () use ($keyA, $keyB) {
    it('round-trip preserves plaintext including query string and fragment', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com/path?a=1&b=2#frag');

        $encrypted = $cipher->encrypt($url);
        $decrypted = $cipher->decrypt($encrypted);

        expect($decrypted->value())->toBe('https://example.com/path?a=1&b=2#frag');
    });

    it('round-trip preserves percent-encoding', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com/path?q=hello%20world');

        $encrypted = $cipher->encrypt($url);
        $decrypted = $cipher->decrypt($encrypted);

        expect($decrypted->value())->toBe('https://example.com/path?q=hello%20world');
    });

    it('two encryptions of the same url produce different envelopes (unique nonce)', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');

        $enc1 = $cipher->encrypt($url);
        $enc2 = $cipher->encrypt($url);

        expect($enc1->envelope())->not->toBe($enc2->envelope());
    });

    it('returns active key_id in the encrypted destination', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');

        $encrypted = $cipher->encrypt($url);

        expect($encrypted->keyId())->toBe('k1');
    });

    it('throws when decrypting with a key_id not in keyring', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');
        $encrypted = $cipher->encrypt($url);

        // Simulate an envelope referencing a key that is not in this keyring instance
        $wrongKeyEnvelope = EncryptedDestination::fromParts($encrypted->envelope(), 'missing-key');

        $cipher->decrypt($wrongKeyEnvelope);
    })->throws(DestinationDecryptionFailed::class);

    it('throws when decrypting with a different key than used for encryption', function () use ($keyA, $keyB) {
        $cipherA = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');

        $encrypted = $cipherA->encrypt($url);

        // Cipher with key B but same key_id: simulates wrong key
        $cipherB = makeCipher(['k1' => $keyB], 'k1');

        $cipherB->decrypt($encrypted);
    })->throws(DestinationDecryptionFailed::class);

    it('throws when nonce is tampered', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');
        $encrypted = $cipher->encrypt($url);

        $binary = base64_decode($encrypted->envelope(), strict: true);
        // Flip a byte in the nonce (offset 1)
        $binary[2] = chr(ord($binary[2]) ^ 0xFF);
        $tampered = EncryptedDestination::fromParts(base64_encode($binary), $encrypted->keyId());

        $cipher->decrypt($tampered);
    })->throws(DestinationDecryptionFailed::class);

    it('throws when tag is tampered', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');
        $encrypted = $cipher->encrypt($url);

        $binary = base64_decode($encrypted->envelope(), strict: true);
        // Flip a byte in the tag (offset 1 + 12 = 13)
        $binary[13] = chr(ord($binary[13]) ^ 0xFF);
        $tampered = EncryptedDestination::fromParts(base64_encode($binary), $encrypted->keyId());

        $cipher->decrypt($tampered);
    })->throws(DestinationDecryptionFailed::class);

    it('throws when ciphertext is tampered', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');
        $encrypted = $cipher->encrypt($url);

        $binary = base64_decode($encrypted->envelope(), strict: true);
        // Flip last byte (ciphertext region)
        $last = strlen($binary) - 1;
        $binary[$last] = chr(ord($binary[$last]) ^ 0xFF);
        $tampered = EncryptedDestination::fromParts(base64_encode($binary), $encrypted->keyId());

        $cipher->decrypt($tampered);
    })->throws(DestinationDecryptionFailed::class);

    it('throws on unknown version byte', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $url = DestinationUrl::fromString('https://example.com');
        $encrypted = $cipher->encrypt($url);

        $binary = base64_decode($encrypted->envelope(), strict: true);
        // Change version byte to unknown (0x02)
        $binary[0] = "\x02";
        $bad = EncryptedDestination::fromParts(base64_encode($binary), $encrypted->keyId());

        $cipher->decrypt($bad);
    })->throws(DestinationDecryptionFailed::class);

    it('throws on invalid base64 envelope', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $bad = EncryptedDestination::fromParts('not!!!valid---base64===', 'k1');

        $cipher->decrypt($bad);
    })->throws(DestinationDecryptionFailed::class);

    it('throws on envelope that is too short', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        // Only 5 bytes — shorter than required header
        $bad = EncryptedDestination::fromParts(base64_encode("\x01\x00\x00\x00\x00"), 'k1');

        $cipher->decrypt($bad);
    })->throws(DestinationDecryptionFailed::class);

    it('decrypts envelope encrypted with an old key when keyring contains both keys', function () use ($keyA, $keyB) {
        // Encrypt with key A (old key)
        $cipherOld = makeCipher(['k1' => $keyA, 'k2' => $keyB], 'k1');
        $url = DestinationUrl::fromString('https://example.com/old-key');
        $encrypted = $cipherOld->encrypt($url);

        // Create cipher with active_key_id pointing to new key B, but k1 still in keyring
        $cipherNew = makeCipher(['k1' => $keyA, 'k2' => $keyB], 'k2');

        $decrypted = $cipherNew->decrypt($encrypted);

        expect($decrypted->value())->toBe('https://example.com/old-key');
    });

    it('envelope does not contain the plaintext URL as a substring', function () use ($keyA) {
        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $plaintext = 'https://supersecret.example.com/do-not-leak';
        $url = DestinationUrl::fromString($plaintext);

        $encrypted = $cipher->encrypt($url);

        expect($encrypted->envelope())->not->toContain($plaintext);
        expect(base64_decode($encrypted->envelope()))->not->toContain($plaintext);
    });

    it('failed decryption does not log the url or envelope', function () use ($keyA) {
        // Laravel 13 has no Log::fake(); MessageLogged + Log::listen is the native spy.
        $captured = [];
        Log::listen(function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event;
        });

        $cipher = makeCipher(['k1' => $keyA], 'k1');
        $bad = EncryptedDestination::fromParts('not!!!valid---base64===', 'k1');

        try {
            $cipher->decrypt($bad);
        } catch (DestinationDecryptionFailed) {
            // expected
        }

        // No log entries must be emitted on decryption failure.
        expect($captured)->toBeEmpty();

        foreach ($captured as $event) {
            expect($event->message)->not->toContain('not!!!valid---base64===');
            expect(json_encode($event->context))->not->toContain('not!!!valid---base64===');
        }
    });
});
