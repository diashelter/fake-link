<?php

declare(strict_types=1);

namespace Modules\Links\Contracts\Services;

use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Exceptions\DestinationDecryptionFailed;

interface DestinationCipher
{
    /**
     * Encrypt a destination URL and return an envelope.
     */
    public function encrypt(DestinationUrl $url): EncryptedDestination;

    /**
     * Decrypt an envelope and return the original destination URL.
     *
     * @throws DestinationDecryptionFailed on any failure (tampered, wrong key, malformed envelope, unknown version)
     */
    public function decrypt(EncryptedDestination $envelope): DestinationUrl;
}
