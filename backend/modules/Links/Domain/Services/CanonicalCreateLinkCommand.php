<?php

declare(strict_types=1);

namespace Modules\Links\Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Services\IdempotencyHmacSecrets;
use Modules\Links\Domain\ValueObjects\IdempotencyKey;
use Modules\Links\DTOs\Input\CreateLinkInput;
use RuntimeException;

/**
 * Builds deterministic create-link command fingerprints and purpose-scoped key hashes.
 *
 * Canonical payload: POST + /api/v1/links + lexicographic JSON of normalized fields.
 * Alias is lowercased; title is trimmed (empty → null); expires_at is strict UTC Z;
 * absent optionals are null. JSON property order does not affect the fingerprint.
 */
final class CanonicalCreateLinkCommand
{
    private const METHOD = 'POST';

    private const PATH = '/api/v1/links';

    private const ASCII_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const ASCII_LOWER = 'abcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private readonly IdempotencyHmacSecrets $secrets,
    ) {}

    public function hashKey(UserId $userId, IdempotencyKey $key): string
    {
        return $this->hmac($this->secrets->keyHashSecret(), $userId->value()."\0".$key->value());
    }

    public function fingerprint(UserId $userId, CreateLinkInput $input): string
    {
        return $this->hmac($this->secrets->fingerprintSecret(), $userId->value()."\0".$this->canonicalPayload($input));
    }

    /**
     * Deterministic UTF-8 bytes of the canonical command (no HMAC).
     */
    public function canonicalPayload(CreateLinkInput $input): string
    {
        $body = [
            'custom_alias' => $this->canonicalizeAlias($input->customAlias),
            'destination_url' => $input->destinationUrl,
            'expires_at' => $this->canonicalizeExpiresAt($input->expiresAt),
            'title' => $this->canonicalizeTitle($input->title),
        ];

        ksort($body);

        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode canonical create-link payload.', 0, $exception);
        }

        return self::METHOD."\n".self::PATH."\n".$json;
    }

    private function canonicalizeAlias(?string $alias): ?string
    {
        if ($alias === null) {
            return null;
        }

        return strtr($alias, self::ASCII_UPPER, self::ASCII_LOWER);
    }

    private function canonicalizeTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $trimmed = trim($title);

        return $trimmed === '' ? null : $trimmed;
    }

    private function canonicalizeExpiresAt(?DateTimeImmutable $expiresAt): ?string
    {
        if ($expiresAt === null) {
            return null;
        }

        return $expiresAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function hmac(string $secret, string $message): string
    {
        return hash_hmac('sha256', $message, $secret);
    }
}
