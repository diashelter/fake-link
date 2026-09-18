<?php

declare(strict_types=1);

use Modules\Links\Contracts\Services\CursorSigningKey;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\ValueObjects\ShortLinkId;
use Modules\Links\DTOs\CursorAnchor;
use Modules\Links\DTOs\LinkQueryScope;
use Modules\Links\Exceptions\InvalidCursor;
use Modules\Links\Exceptions\InvalidCursorReason;
use Modules\Links\Infrastructure\Crypto\ConfigCursorSigningKey;
use Modules\Links\Infrastructure\Pagination\HmacCursorCodec;

final class FixedCursorSigningKey implements CursorSigningKey
{
    public function __construct(private readonly string $key) {}

    public function value(): string
    {
        return $this->key;
    }
}

/**
 * @return array<string, mixed>
 */
function decodeCursorJson(string $cursor): array
{
    $parts = explode('.', $cursor);
    expect($parts)->toHaveCount(2);

    $padded = strtr($parts[0], '-_', '+/');
    $remainder = strlen($padded) % 4;
    if ($remainder !== 0) {
        $padded .= str_repeat('=', 4 - $remainder);
    }

    $json = base64_decode($padded, true);
    expect($json)->toBeString();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $payload;
}

function signCursorPayload(string $json, string $key): string
{
    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $json, $key, true)), '+/', '-_'), '=');

    return $payload.'.'.$mac;
}

describe('HmacCursorCodec', function () {
    beforeEach(function () {
        $this->key = 'unit-test-cursor-hmac-key';
        $this->codec = new HmacCursorCodec(new FixedCursorSigningKey($this->key));
        $this->anchor = new CursorAnchor(
            createdAt: new DateTimeImmutable('2026-09-18T12:00:00Z'),
            id: ShortLinkId::fromString('01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12'),
        );
        $this->scope = new LinkQueryScope(search: 'campanha', status: LinkStatus::Active);
    });

    it('encodes only version, UTC created_at, UUID v7 id, and normalized search/status', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);
        $payload = decodeCursorJson($cursor);

        expect($payload)->toBe([
            'v' => 1,
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00Z',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => [
                'search' => 'campanha',
                'status' => 'active',
            ],
        ])
            ->and($payload)->not->toHaveKey('user_id')
            ->and($payload)->not->toHaveKey('per_page')
            ->and($payload['anchor'])->not->toHaveKey('user_id')
            ->and($payload['scope'])->not->toHaveKey('user_id')
            ->and($payload['scope'])->not->toHaveKey('per_page');
    });

    it('round-trips a signed cursor for the same search and status scope', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);
        $decoded = $this->codec->decode($cursor, $this->scope);

        expect($decoded->id->value())->toBe($this->anchor->id->value())
            ->and($decoded->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
            ->toBe('2026-09-18T12:00:00Z');
    });

    it('does not bind per_page so a page-size change keeps the cursor valid', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);
        $payload = decodeCursorJson($cursor);

        expect($payload)->not->toHaveKey('per_page');

        $decoded = $this->codec->decode($cursor, $this->scope);

        expect($decoded->id->value())->toBe($this->anchor->id->value());
    });

    it('rejects a cursor when search changes', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);

        try {
            $this->codec->decode($cursor, new LinkQueryScope(search: 'outra', status: LinkStatus::Active));
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::ScopeMismatch)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR');
        }
    });

    it('rejects a cursor when status changes', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);

        try {
            $this->codec->decode($cursor, new LinkQueryScope(search: 'campanha', status: LinkStatus::Expired));
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::ScopeMismatch)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR');
        }
    });

    it('rejects an empty or malformed cursor', function (string $cursor) {
        try {
            $this->codec->decode($cursor, $this->scope);
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::Malformed)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR');
        }
    })->with([
        '',
        'not-a-cursor',
        'onlyonepart',
        'abc.def.ghi',
        '@@@.@@@',
    ]);

    it('rejects a tampered signature', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);
        $parts = explode('.', $cursor);
        $tampered = $parts[0].'.'.str_repeat('A', strlen($parts[1]));

        try {
            $this->codec->decode($tampered, $this->scope);
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::InvalidSignature)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR');
        }
    });

    it('rejects a cursor signed with a different HMAC key than the dedicated cursor key', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);
        $other = new HmacCursorCodec(new FixedCursorSigningKey('a-different-cursor-hmac-key'));

        try {
            $other->decode($cursor, $this->scope);
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::InvalidSignature);
        }
    });

    it('does not reuse the ETag HMAC key for cursor signatures', function () {
        $etagKey = new ConfigCursorSigningKey('testing-links-etag-hmac-key');
        $cursorKey = new ConfigCursorSigningKey('unit-test-cursor-hmac-key');

        expect($cursorKey->value())->not->toBe($etagKey->value());

        $withCursorKey = (new HmacCursorCodec($cursorKey))->encode($this->anchor, $this->scope);
        $withEtagKey = (new HmacCursorCodec($etagKey))->encode($this->anchor, $this->scope);

        expect($withCursorKey)->not->toBe($withEtagKey);
    });

    it('rejects an unsupported cursor version', function () {
        $json = json_encode([
            'v' => 2,
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00Z',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => [
                'search' => 'campanha',
                'status' => 'active',
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->codec->decode(signCursorPayload($json, $this->key), $this->scope);
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::UnsupportedVersion)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR');
        }
    });

    it('rejects invalid payload types including a non-UUID-v7 id', function (array $payload) {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $this->codec->decode(signCursorPayload($json, $this->key), $this->scope);
            $this->fail('Expected InvalidCursor');
        } catch (InvalidCursor $exception) {
            expect($exception->reason())->toBe(InvalidCursorReason::InvalidTypes)
                ->and($exception->errorCode())->toBe('INVALID_CURSOR');
        }
    })->with([
        'string version' => [[
            'v' => '1',
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00Z',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => ['search' => 'campanha', 'status' => 'active'],
        ]],
        'uuid v4 id' => [[
            'v' => 1,
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00Z',
                'id' => '550e8400-e29b-41d4-a716-446655440000',
            ],
            'scope' => ['search' => 'campanha', 'status' => 'active'],
        ]],
        'non-utc created_at' => [[
            'v' => 1,
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00+00:00',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => ['search' => 'campanha', 'status' => 'active'],
        ]],
        'numeric search' => [[
            'v' => 1,
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00Z',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => ['search' => 12, 'status' => 'active'],
        ]],
        'user_id extra key' => [[
            'v' => 1,
            'anchor' => [
                'created_at' => '2026-09-18T12:00:00Z',
                'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            ],
            'scope' => ['search' => 'campanha', 'status' => 'active'],
            'user_id' => '01936b2e-aaaa-7f3d-9e1b-2a4c6d8e0f12',
        ]],
    ]);

    it('never accepts user_id from a cursor as a decoded field', function () {
        $cursor = $this->codec->encode($this->anchor, $this->scope);
        $payload = decodeCursorJson($cursor);
        $decoded = $this->codec->decode($cursor, $this->scope);

        expect($payload)->not->toHaveKey('user_id')
            ->and(array_map(
                fn (ReflectionProperty $property) => $property->getName(),
                (new ReflectionClass($decoded))->getProperties(),
            ))->toBe(['createdAt', 'id']);
    });
});
