<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Crypto;

use RuntimeException;

/**
 * Exclusive AES-256 keyring for idempotency response snapshots.
 * Must never share keys with the destination keyring.
 */
final class IdempotencyKeyring
{
    /**
     * @param  array<string, string>  $keys  Map of key_id => raw 32-byte key binary.
     */
    private function __construct(
        private readonly array $keys,
        private readonly string $activeKeyId,
    ) {}

    /**
     * @param  array{keyring: string, active_key_id: string}  $config  config('links.idempotency')
     *
     * @throws RuntimeException on invalid config
     */
    public static function fromConfig(array $config): self
    {
        $activeKeyId = $config['active_key_id'];
        $keyringJson = $config['keyring'];

        $decoded = json_decode($keyringJson, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Idempotency keyring config is malformed: JSON is invalid.');
        }

        if (count($decoded) === 0) {
            throw new RuntimeException('Idempotency keyring config is invalid: keyring must not be empty.');
        }

        if ($activeKeyId === '') {
            throw new RuntimeException('Idempotency keyring config is invalid: active_key_id is missing.');
        }

        $keys = [];

        foreach ($decoded as $keyId => $encodedKey) {
            $raw = base64_decode((string) $encodedKey, strict: true);

            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException(
                    sprintf('Idempotency keyring config is invalid: key "%s" must be exactly 32 bytes after base64 decode.', $keyId),
                );
            }

            $keys[(string) $keyId] = $raw;
        }

        if (! array_key_exists($activeKeyId, $keys)) {
            throw new RuntimeException('Idempotency keyring config is invalid: active_key_id is not present in the keyring map.');
        }

        return new self($keys, $activeKeyId);
    }

    /**
     * @throws RuntimeException when the key_id is not found
     */
    public function keyFor(string $keyId): string
    {
        if (! array_key_exists($keyId, $this->keys)) {
            throw new RuntimeException(sprintf('Idempotency keyring: key_id "%s" not found in keyring.', $keyId));
        }

        return $this->keys[$keyId];
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }
}
