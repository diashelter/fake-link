<?php

declare(strict_types=1);

namespace Modules\Links\Exceptions;

use RuntimeException;

final class IdempotencySnapshotDecryptionFailed extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function tampered(): self
    {
        return new self('Idempotency snapshot decryption failed: authentication tag verification failed.');
    }

    public static function unknownVersion(): self
    {
        return new self('Idempotency snapshot decryption failed: unknown envelope version.');
    }

    public static function malformedEnvelope(): self
    {
        return new self('Idempotency snapshot decryption failed: envelope is malformed or too short.');
    }

    public static function keyNotFound(): self
    {
        return new self('Idempotency snapshot decryption failed: encryption key not found in keyring.');
    }

    public static function malformedPayload(): self
    {
        return new self('Idempotency snapshot decryption failed: plaintext payload is malformed.');
    }
}
