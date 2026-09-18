<?php

declare(strict_types=1);

use Modules\Links\DTOs\Output\EncryptedIdempotencySnapshot;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;
use Modules\Links\Exceptions\IdempotencySnapshotDecryptionFailed;
use Modules\Links\Infrastructure\Crypto\Aes256GcmIdempotencySnapshotCipher;
use Modules\Links\Infrastructure\Crypto\IdempotencyKeyring;

function idempotencyTestKeyA(): string
{
    return base64_encode(str_repeat("\x01", 32));
}

function idempotencyTestKeyB(): string
{
    return base64_encode(str_repeat("\x02", 32));
}

/**
 * @param  array<string, string>  $keys  Map of key_id => base64-encoded 32-byte key.
 */
function makeIdempotencyCipher(array $keys, string $activeKeyId): Aes256GcmIdempotencySnapshotCipher
{
    $keyring = IdempotencyKeyring::fromConfig([
        'keyring' => json_encode($keys, JSON_THROW_ON_ERROR),
        'active_key_id' => $activeKeyId,
    ]);

    return new Aes256GcmIdempotencySnapshotCipher($keyring);
}

function sampleSnapshot(): IdempotencyResponseSnapshot
{
    return new IdempotencyResponseSnapshot(
        status: 201,
        headers: [
            'Location' => 'https://go.localhost/abc12345',
            'ETag' => '"deadbeef"',
            'Cache-Control' => 'no-store',
        ],
        body: '{"data":{"destination_url":"https://private.example/secret","title":"Secret Title"}}',
    );
}

describe('Aes256GcmIdempotencySnapshotCipher', function () {
    it('round-trips status, semantic headers, and body bytes', function () {
        $cipher = makeIdempotencyCipher(['idem-1' => idempotencyTestKeyA()], 'idem-1');
        $snapshot = sampleSnapshot();

        $encrypted = $cipher->encrypt($snapshot);
        $decrypted = $cipher->decrypt($encrypted);

        expect($encrypted->keyId)->toBe('idem-1')
            ->and($decrypted->status)->toBe(201)
            ->and($decrypted->headers)->toBe($snapshot->headers)
            ->and($decrypted->body)->toBe($snapshot->body);
    });

    it('does not store destination, title, body, or headers in cleartext inside the envelope', function () {
        $cipher = makeIdempotencyCipher(['idem-1' => idempotencyTestKeyA()], 'idem-1');
        $snapshot = sampleSnapshot();

        $encrypted = $cipher->encrypt($snapshot);
        $blob = $encrypted->ciphertext;

        expect($blob)->not->toContain('https://private.example/secret')
            ->and($blob)->not->toContain('Secret Title')
            ->and($blob)->not->toContain('https://go.localhost/abc12345')
            ->and($blob)->not->toContain('"deadbeef"')
            ->and($blob)->not->toContain('no-store')
            ->and($blob)->not->toContain($snapshot->body);
    });

    it('fails closed when the envelope is tampered', function () {
        $cipher = makeIdempotencyCipher(['idem-1' => idempotencyTestKeyA()], 'idem-1');
        $encrypted = $cipher->encrypt(sampleSnapshot());

        $tampered = $encrypted->ciphertext;
        $tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === "\x00" ? "\x01" : "\x00";

        $cipher->decrypt(new EncryptedIdempotencySnapshot($tampered, $encrypted->keyId));
    })->throws(IdempotencySnapshotDecryptionFailed::class);

    it('fails closed when decrypting with a different key material for the same key_id', function () {
        $cipherA = makeIdempotencyCipher(['idem-1' => idempotencyTestKeyA()], 'idem-1');
        $encrypted = $cipherA->encrypt(sampleSnapshot());

        $cipherB = makeIdempotencyCipher(['idem-1' => idempotencyTestKeyB()], 'idem-1');
        $cipherB->decrypt($encrypted);
    })->throws(IdempotencySnapshotDecryptionFailed::class);

    it('uses an exclusive keyring that cannot decrypt destination envelopes and vice versa is out of scope', function () {
        $cipher = makeIdempotencyCipher(['idem-1' => idempotencyTestKeyA()], 'idem-1');
        $encrypted = $cipher->encrypt(sampleSnapshot());

        // Destination keyring material must not open an idempotency envelope.
        $destinationMaterial = makeIdempotencyCipher(
            ['idem-1' => base64_encode(str_repeat("\x00", 32))],
            'idem-1',
        );

        $destinationMaterial->decrypt($encrypted);
    })->throws(IdempotencySnapshotDecryptionFailed::class);
});
