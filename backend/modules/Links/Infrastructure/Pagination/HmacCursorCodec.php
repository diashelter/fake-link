<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Pagination;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Modules\Links\Contracts\Services\CursorCodec;
use Modules\Links\Contracts\Services\CursorSigningKey;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\LinkQueryScope;
use Modules\Links\Exceptions\InvalidCursor;
use Modules\Links\Exceptions\LinksDomainException;

final class HmacCursorCodec implements CursorCodec
{
    private const VERSION = 1;

    private const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly CursorSigningKey $signingKey,
    ) {}

    public function encode(CursorAnchor $anchor, LinkQueryScope $scope): string
    {
        $json = json_encode([
            'v' => self::VERSION,
            'anchor' => [
                'created_at' => $anchor->createdAt
                    ->setTimezone(new DateTimeZone('UTC'))
                    ->format(self::UTC_FORMAT),
                'id' => $anchor->id->value(),
            ],
            'scope' => [
                'search' => $scope->search,
                'status' => $scope->status instanceof LinkStatus ? $scope->status->value : 'all',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $this->base64UrlEncode($json).'.'.$this->base64UrlEncode($this->mac($json));
    }

    public function decode(string $cursor, LinkQueryScope $expectedScope): CursorAnchor
    {
        $parts = explode('.', $cursor);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw InvalidCursor::malformed();
        }

        $json = $this->base64UrlDecode($parts[0]);
        $signature = $this->base64UrlDecode($parts[1]);

        if ($json === null || $signature === null) {
            throw InvalidCursor::malformed();
        }

        if (! hash_equals($this->mac($json), $signature)) {
            throw InvalidCursor::invalidSignature();
        }

        $payload = $this->decodePayload($json);
        $this->assertScope($payload['scope'], $expectedScope);

        return $payload['anchor'];
    }

    /**
     * @return array{anchor: CursorAnchor, scope: LinkQueryScope}
     */
    private function decodePayload(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidCursor::malformed();
        }

        if (! is_array($decoded) || array_keys($decoded) !== ['v', 'anchor', 'scope']) {
            throw InvalidCursor::invalidTypes();
        }

        if (! is_int($decoded['v'])) {
            throw InvalidCursor::invalidTypes();
        }

        if ($decoded['v'] !== self::VERSION) {
            throw InvalidCursor::unsupportedVersion();
        }

        return [
            'anchor' => $this->parseAnchor($decoded['anchor']),
            'scope' => $this->parseScope($decoded['scope']),
        ];
    }

    private function parseAnchor(mixed $anchor): CursorAnchor
    {
        if (! is_array($anchor) || array_keys($anchor) !== ['created_at', 'id']) {
            throw InvalidCursor::invalidTypes();
        }

        if (! is_string($anchor['created_at']) || ! is_string($anchor['id'])) {
            throw InvalidCursor::invalidTypes();
        }

        $createdAt = DateTimeImmutable::createFromFormat(
            self::UTC_FORMAT,
            $anchor['created_at'],
            new DateTimeZone('UTC'),
        );

        if ($createdAt === false || $createdAt->format(self::UTC_FORMAT) !== $anchor['created_at']) {
            throw InvalidCursor::invalidTypes();
        }

        try {
            $id = ShortLinkId::fromString($anchor['id']);
        } catch (LinksDomainException) {
            throw InvalidCursor::invalidTypes();
        }

        return new CursorAnchor($createdAt, $id);
    }

    private function parseScope(mixed $scope): LinkQueryScope
    {
        if (! is_array($scope) || array_keys($scope) !== ['search', 'status']) {
            throw InvalidCursor::invalidTypes();
        }

        if (! is_string($scope['search']) && $scope['search'] !== null) {
            throw InvalidCursor::invalidTypes();
        }

        if (! is_string($scope['status'])) {
            throw InvalidCursor::invalidTypes();
        }

        $status = $scope['status'] === 'all' ? null : LinkStatus::tryFrom($scope['status']);

        if ($scope['status'] !== 'all' && $status === null) {
            throw InvalidCursor::invalidTypes();
        }

        return new LinkQueryScope($scope['search'], $status);
    }

    private function assertScope(LinkQueryScope $actual, LinkQueryScope $expected): void
    {
        if (! $actual->equals($expected)) {
            throw InvalidCursor::scopeMismatch();
        }
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->signingKey->value(), true);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
