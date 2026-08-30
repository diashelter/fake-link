<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Crypto;

use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Exceptions\DestinationDecryptionFailed;
use RuntimeException;

final class Aes256GcmDestinationCipher implements DestinationCipher
{
    private const VERSION = "\x01";

    private const VERSION_BYTE = 0x01;

    private const NONCE_BYTES = 12;

    private const TAG_BYTES = 16;

    private const HEADER_BYTES = 1 + self::NONCE_BYTES + self::TAG_BYTES; // version + nonce + tag

    private const AAD_PREFIX = 'fld-destination-v1|';

    private const ALGO = 'aes-256-gcm';

    public function __construct(private readonly DestinationKeyring $keyring) {}

    public function encrypt(DestinationUrl $url): EncryptedDestination
    {
        $keyId = $this->keyring->activeKeyId();
        $key = $this->keyring->keyFor($keyId);

        $nonce = random_bytes(self::NONCE_BYTES);
        $aad = self::AAD_PREFIX.$keyId;

        $tag = '';
        $ciphertext = openssl_encrypt(
            data: $url->value(),
            cipher_algo: self::ALGO,
            passphrase: $key,
            options: OPENSSL_RAW_DATA,
            iv: $nonce,
            tag: $tag,
            aad: $aad,
            tag_length: self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        $binary = self::VERSION.$nonce.$tag.$ciphertext;
        $envelope = base64_encode($binary);

        return EncryptedDestination::fromParts($envelope, $keyId);
    }

    public function decrypt(EncryptedDestination $encrypted): DestinationUrl
    {
        $keyId = $encrypted->keyId();

        try {
            $key = $this->keyring->keyFor($keyId);
        } catch (RuntimeException) {
            throw DestinationDecryptionFailed::keyNotFound();
        }

        $binary = base64_decode($encrypted->envelope(), strict: true);

        if ($binary === false || strlen($binary) < self::HEADER_BYTES + 1) {
            throw DestinationDecryptionFailed::malformedEnvelope();
        }

        $version = ord($binary[0]);

        if ($version !== self::VERSION_BYTE) {
            throw DestinationDecryptionFailed::unknownVersion();
        }

        $nonce = substr($binary, 1, self::NONCE_BYTES);
        $tag = substr($binary, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($binary, self::HEADER_BYTES);

        if (strlen($ciphertext) < 1) {
            throw DestinationDecryptionFailed::malformedEnvelope();
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
            throw DestinationDecryptionFailed::tampered();
        }

        return DestinationUrl::fromString($plaintext);
    }
}
