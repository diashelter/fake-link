<?php

declare(strict_types=1);

namespace Modules\Links\Domain\ValueObjects;

final readonly class EncryptedDestination
{
    /**
     * @param  string  $envelope  Base64-encoded ciphertext envelope.
     * @param  string  $keyId     The key_id used to encrypt (stored separately from the envelope).
     */
    private function __construct(
        private string $envelope,
        private string $keyId,
    ) {}

    public static function fromParts(string $envelope, string $keyId): self
    {
        return new self($envelope, $keyId);
    }

    public function envelope(): string
    {
        return $this->envelope;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }
}
