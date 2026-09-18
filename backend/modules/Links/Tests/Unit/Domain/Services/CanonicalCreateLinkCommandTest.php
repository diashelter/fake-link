<?php

declare(strict_types=1);

use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Services\IdempotencyHmacSecrets;
use Modules\Links\Domain\Services\CanonicalCreateLinkCommand;
use Modules\Links\Domain\ValueObjects\IdempotencyKey;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\Infrastructure\Crypto\ConfigIdempotencyHmacSecrets;

function idempotencySecrets(
    string $keyHash = 'purpose-key-hash-secret',
    string $fingerprint = 'purpose-fingerprint-secret',
): IdempotencyHmacSecrets {
    return new ConfigIdempotencyHmacSecrets($keyHash, $fingerprint);
}

function idempotencyUser(string $uuid = '0193e0a0-0000-7000-8000-000000000001'): UserId
{
    return UserId::fromString($uuid);
}

function createLinkInput(
    string $destinationUrl = 'https://example.com/path',
    ?string $customAlias = 'architecture',
    ?string $title = 'Architecture Notes',
    ?DateTimeImmutable $expiresAt = null,
): CreateLinkInput {
    return new CreateLinkInput(
        destinationUrl: $destinationUrl,
        customAlias: $customAlias,
        title: $title,
        expiresAt: $expiresAt,
    );
}

describe('CanonicalCreateLinkCommand', function () {
    it('produces the same fingerprint for JSON field order, alias case, title whitespace, and absent vs null optionals', function () {
        $command = new CanonicalCreateLinkCommand(idempotencySecrets());
        $user = idempotencyUser();
        $expires = new DateTimeImmutable('2026-12-01T00:00:00Z', new DateTimeZone('UTC'));

        $a = $command->fingerprint($user, createLinkInput(
            customAlias: 'Architecture',
            title: '  Architecture Notes  ',
            expiresAt: $expires,
        ));

        $b = $command->fingerprint($user, createLinkInput(
            customAlias: 'architecture',
            title: 'Architecture Notes',
            expiresAt: $expires,
        ));

        $c = $command->fingerprint($user, new CreateLinkInput(
            destinationUrl: 'https://example.com/path',
            customAlias: 'architecture',
            title: null,
            expiresAt: $expires,
        ));

        $d = $command->fingerprint($user, new CreateLinkInput(
            destinationUrl: 'https://example.com/path',
            customAlias: 'architecture',
            title: '   ',
            expiresAt: $expires,
        ));

        expect($a)->toBe($b)
            ->and($c)->toBe($d)
            ->and($a)->not->toBe($c);
    });

    it('changes fingerprint when any normalized payload field changes', function () {
        $command = new CanonicalCreateLinkCommand(idempotencySecrets());
        $user = idempotencyUser();
        $base = createLinkInput();

        $destinationChanged = createLinkInput(destinationUrl: 'https://example.com/other');
        $aliasChanged = createLinkInput(customAlias: 'different');
        $titleChanged = createLinkInput(title: 'Other title');
        $expiresChanged = createLinkInput(
            expiresAt: new DateTimeImmutable('2027-01-01T00:00:00Z', new DateTimeZone('UTC')),
        );

        $baseFp = $command->fingerprint($user, $base);

        expect($command->fingerprint($user, $destinationChanged))->not->toBe($baseFp)
            ->and($command->fingerprint($user, $aliasChanged))->not->toBe($baseFp)
            ->and($command->fingerprint($user, $titleChanged))->not->toBe($baseFp)
            ->and($command->fingerprint($user, $expiresChanged))->not->toBe($baseFp);
    });

    it('scopes fingerprints and key hashes by user and purpose-distinct HMAC secrets', function () {
        $secrets = idempotencySecrets();
        $command = new CanonicalCreateLinkCommand($secrets);
        $userA = idempotencyUser('0193e0a0-0000-7000-8000-000000000001');
        $userB = idempotencyUser('0193e0a0-0000-7000-8000-000000000002');
        $input = createLinkInput();
        $key = IdempotencyKey::fromString('idem-key-abcdefgh');

        expect($command->fingerprint($userA, $input))->not->toBe($command->fingerprint($userB, $input))
            ->and($command->hashKey($userA, $key))->not->toBe($command->hashKey($userB, $key));

        $otherPurpose = new CanonicalCreateLinkCommand(idempotencySecrets(
            keyHash: 'purpose-fingerprint-secret',
            fingerprint: 'purpose-key-hash-secret',
        ));

        expect($command->fingerprint($userA, $input))->not->toBe($otherPurpose->fingerprint($userA, $input))
            ->and($command->hashKey($userA, $key))->not->toBe($otherPurpose->hashKey($userA, $key))
            ->and($command->hashKey($userA, $key))->not->toBe($command->fingerprint($userA, $input));
    });
});
