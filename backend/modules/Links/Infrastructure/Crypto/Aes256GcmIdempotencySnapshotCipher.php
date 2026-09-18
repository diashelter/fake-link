<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Crypto;

use JsonException;
use Modules\Links\Contracts\Services\IdempotencySnapshotCipher;
use Modules\Links\DTOs\Output\EncryptedIdempotencySnapshot;
use Modules\Links\DTOs\Output\IdempotencyResponseSnapshot;
use Modules\Links\Exceptions\IdempotencySnapshotDecryptionFailed;
use RuntimeException;

/**
 * AES-256-GCM envelope for idempotency snapshots.
 * AAD and keyring are exclusive to idempotency — never reuse destination material.
 */
final class Aes256GcmIdempotencySnapshotCipher implements IdempotencySnapshotCipher
{
    private const VERSION = "\x01";

    private const VERSION_BYTE = 0x01;

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    private const HEADER_BYTES = 1 + self::NONCE_BYTES + self::TAG_BYTES;

    private const AAD_PREFIX = 'fld-idempotency-v1|';

    private const ALGO = 'aes-256-gcm';

    public function __construct(
        private readonly IdempotencyKeyring $keyring,
    ) {}

    public function encrypt(IdempotencyResponseSnapshot $snapshot): EncryptedIdempotencySnapshot
    {
        $keyId = $this->keyring->activeKeyId();
        $key = $this->keyring->keyFor($keyId);

        $plaintext = $this->encodePayload($snapshot);
        $nonce = random_bytes(self::NONCE_BYTES);
        $aad = self::AAD_PREFIX.$keyId;

        $tag = '';
        $ciphertext = openssl_encrypt(
            data: $plaintext,
            cipher_algo: self::ALGO,
            passphrase: $key,
            options: OPENSSL_RAW_DATA,
            iv: $nonce,
            tag: $tag,
            aad: $aad,
            tag_length: self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM idempotency encryption failed.');
        }

        $binary = self::VERSION.$nonce.$tag.$ciphertext;

        return new EncryptedIdempotencySnapshot($binary, $keyId);
    }

    public function decrypt(EncryptedIdempotencySnapshot $encrypted): IdempotencyResponseSnapshot
    {
        $keyId = $encrypted->keyId;

        try {
            $key = $this->keyring->keyFor($keyId);
        } catch (RuntimeException) {
            throw IdempotencySnapshotDecryptionFailed::keyNotFound();
        }

        $binary = $encrypted->ciphertext;

        if (strlen($binary) < self::HEADER_BYTES + 1) {
            throw IdempotencySnapshotDecryptionFailed::malformedEnvelope();
        }

        $version = ord($binary[0]);

        if ($version !== self::VERSION_BYTE) {
            throw IdempotencySnapshotDecryptionFailed::unknownVersion();
        }

        $nonce = substr($binary, 1, self::NONCE_BYTES);
        $tag = substr($binary, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($binary, self::HEADER_BYTES);

        if (strlen($ciphertext) < 1) {
            throw IdempotencySnapshotDecryptionFailed::malformedEnvelope();
        }

        $aad = self::AAD_PREFIX.$keyId;

        $plaintext = openssl_decrypt(
            data: $ciphertext,
            cipher_algo: self::ALGO,
            passphrase: $key,
            options: OPENSSL_RAW_DATA,
            iv: $nonce,
            tag: $tag,
            aad: $aad,
        );

        if ($plaintext === false) {
            throw IdempotencySnapshotDecryptionFailed::tampered();
        }

        return $this->decodePayload($plaintext);
    }

    private function encodePayload(IdempotencyResponseSnapshot $snapshot): string
    {
        $headers = $snapshot->headers;
        ksort($headers);

        try {
            return json_encode(
                [
                    'status' => $snapshot->status,
                    'headers' => $headers,
                    'body' => $snapshot->body,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode idempotency snapshot payload.', 0, $exception);
        }
    }

    private function decodePayload(string $plaintext): IdempotencyResponseSnapshot
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw IdempotencySnapshotDecryptionFailed::malformedPayload();
        }

        if (! is_array($decoded)
            || ! isset($decoded['status'], $decoded['headers'], $decoded['body'])
            || ! is_int($decoded['status'])
            || ! is_array($decoded['headers'])
            || ! is_string($decoded['body'])
            || ! isset($decoded['headers']['Location'], $decoded['headers']['ETag'], $decoded['headers']['Cache-Control'])
            || ! is_string($decoded['headers']['Location'])
            || ! is_string($decoded['headers']['ETag'])
            || ! is_string($decoded['headers']['Cache-Control'])
        ) {
            throw IdempotencySnapshotDecryptionFailed::malformedPayload();
        }

        return new IdempotencyResponseSnapshot(
            status: $decoded['status'],
            headers: [
                'Location' => $decoded['headers']['Location'],
                'ETag' => $decoded['headers']['ETag'],
                'Cache-Control' => $decoded['headers']['Cache-Control'],
            ],
            body: $decoded['body'],
        );
    }
}
