<?php

declare(strict_types=1);

use DateTimeImmutable;
use Modules\Links\Contracts\Services\ETagSigningKey;
use Modules\Links\Domain\Enums\LinkStatus;
use Modules\Links\Domain\Services\LinkETag;

final class FixedETagSigningKey implements ETagSigningKey
{
    public function __construct(private readonly string $key) {}

    public function value(): string
    {
        return $this->key;
    }
}

describe('LinkETag', function () {
    beforeEach(function () {
        $this->etag = new LinkETag(new FixedETagSigningKey('unit-test-etag-hmac-key'));
        $this->base = [
            'id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f12',
            'slug' => 'abc12345',
            'destination' => 'https://example.com/path',
            'title' => 'Launch',
            'isEnabled' => true,
            'expiresAt' => null,
            'blockedAt' => null,
            'updatedAt' => new DateTimeImmutable('2025-01-01T12:00:00Z'),
            'status' => LinkStatus::Active,
        ];

        $this->compute = function (array $overrides = []): string {
            $state = array_merge($this->base, $overrides);

            return $this->etag->for(
                id: $state['id'],
                slug: $state['slug'],
                normalizedDestinationUrl: $state['destination'],
                title: $state['title'],
                isEnabled: $state['isEnabled'],
                expiresAt: $state['expiresAt'],
                blockedAt: $state['blockedAt'],
                updatedAt: $state['updatedAt'],
                effectiveStatus: $state['status'],
            );
        };
    });

    it('returns a strong ETag matching ^"[^"]+"$ without a W/ prefix', function () {
        $value = ($this->compute)();

        expect($value)->toMatch('/^"[^"]+"$/')
            ->and(str_starts_with($value, 'W/'))->toBeFalse();
    });

    it('is deterministic for the same canonical state', function () {
        expect(($this->compute)())->toBe(($this->compute)());
    });

    it('changes when id changes', function () {
        expect(($this->compute)(['id' => '01936b2e-8c4a-7f3d-9e1b-2a4c6d8e0f99']))
            ->not->toBe(($this->compute)());
    });

    it('changes when slug changes', function () {
        expect(($this->compute)(['slug' => 'zzzzzzzz']))->not->toBe(($this->compute)());
    });

    it('changes when normalized destination URL changes', function () {
        expect(($this->compute)(['destination' => 'https://example.com/other']))
            ->not->toBe(($this->compute)());
    });

    it('changes when title changes', function () {
        expect(($this->compute)(['title' => 'Other']))->not->toBe(($this->compute)());
    });

    it('changes when title goes from null to a value', function () {
        expect(($this->compute)(['title' => null]))
            ->not->toBe(($this->compute)(['title' => 'Named']));
    });

    it('changes when is_enabled changes', function () {
        expect(($this->compute)(['isEnabled' => false, 'status' => LinkStatus::Inactive]))
            ->not->toBe(($this->compute)());
    });

    it('changes when expires_at changes', function () {
        expect(($this->compute)(['expiresAt' => new DateTimeImmutable('2026-01-01T00:00:00Z')]))
            ->not->toBe(($this->compute)());
    });

    it('changes when blocked_at changes', function () {
        expect(($this->compute)([
            'blockedAt' => new DateTimeImmutable('2024-06-01T00:00:00Z'),
            'status' => LinkStatus::Blocked,
        ]))->not->toBe(($this->compute)());
    });

    it('changes when updated_at changes', function () {
        expect(($this->compute)(['updatedAt' => new DateTimeImmutable('2025-02-01T12:00:00Z')]))
            ->not->toBe(($this->compute)());
    });

    it('includes effective status so blocked differs from active for the same persisted flags', function () {
        expect(($this->compute)(['status' => LinkStatus::Blocked]))
            ->not->toBe(($this->compute)(['status' => LinkStatus::Active]));
    });

    it('includes effective status so expired differs from active', function () {
        expect(($this->compute)(['status' => LinkStatus::Expired]))
            ->not->toBe(($this->compute)(['status' => LinkStatus::Active]));
    });

    it('does not embed or reverse to version, user_id, or destination URL', function () {
        $destination = 'https://secret-destination.example/private?token=abc';
        $userId = '01936b2e-aaaa-7f3d-9e1b-2a4c6d8e0f12';
        $version = '42';

        $value = ($this->compute)(['destination' => $destination]);
        $inner = trim($value, '"');

        expect($value)->not->toContain($destination)
            ->and($value)->not->toContain($userId)
            ->and($value)->not->toContain($version)
            ->and($inner)->not->toBe($destination)
            ->and($inner)->not->toBe($userId)
            ->and($inner)->not->toBe($version)
            ->and(strlen($inner))->toBe(64)
            ->and(ctype_xdigit($inner))->toBeTrue();
    });

    it('uses a different digest when the signing key changes', function () {
        $other = new LinkETag(new FixedETagSigningKey('a-different-etag-hmac-key'));

        $first = ($this->compute)();
        $second = $other->for(
            id: $this->base['id'],
            slug: $this->base['slug'],
            normalizedDestinationUrl: $this->base['destination'],
            title: $this->base['title'],
            isEnabled: $this->base['isEnabled'],
            expiresAt: $this->base['expiresAt'],
            blockedAt: $this->base['blockedAt'],
            updatedAt: $this->base['updatedAt'],
            effectiveStatus: $this->base['status'],
        );

        expect($first)->not->toBe($second);
    });
});
