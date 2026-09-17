<?php

declare(strict_types=1);

namespace Modules\Links\UseCases;

use Modules\Links\Contracts\Services\DestinationCipher;
use Modules\Links\Domain\Services\PublicHostClassifier;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;

/**
 * The only path from a raw destination string to an encrypted envelope.
 *
 * Every call runs the full destination policy (DestinationUrl::fromString) immediately
 * before encrypting, with no caching or memoization — so both the first version created
 * for a link and every later destination swap revalidate from scratch. A rejected raw
 * value throws before DestinationCipher::encrypt() is ever called.
 */
final readonly class SealDestinationUrl
{
    public function __construct(
        private PublicHostClassifier $hosts,
        private DestinationCipher $cipher,
    ) {}

    public function __invoke(string $raw): EncryptedDestination
    {
        $url = DestinationUrl::fromString($raw, $this->hosts);

        return $this->cipher->encrypt($url);
    }
}
