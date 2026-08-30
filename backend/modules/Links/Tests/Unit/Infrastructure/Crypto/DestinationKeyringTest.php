<?php

declare(strict_types=1);

use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Tests\TestCase;

uses(TestCase::class);

describe('DestinationKeyring', function () {
    // A valid 32-byte key encoded as base64 (44 chars with padding = 32 null bytes).
    // This key is obviously fake and is only used in tests.
    $validKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    $validConfig = [
        'keyring' => json_encode(['key-1' => $validKey]),
        'active_key_id' => 'key-1',
    ];

    it('builds keyring from valid config read from the application config', function () {
        $keyring = DestinationKeyring::fromConfig(
            config('links.destination'),
        );

        expect($keyring->activeKeyId())->toBe('testing-key-1');
    });

    it('returns raw 32-byte key for a known key_id', function () use ($validConfig) {
        $keyring = DestinationKeyring::fromConfig($validConfig);

        $raw = $keyring->keyFor('key-1');

        expect(strlen($raw))->toBe(32);
    });

    it('throws when keyring JSON is malformed', function () {
        DestinationKeyring::fromConfig([
            'keyring' => 'not-valid-json',
            'active_key_id' => 'key-1',
        ]);
    })->throws(RuntimeException::class);

    it('throws when keyring is empty', function () {
        DestinationKeyring::fromConfig([
            'keyring' => '{}',
            'active_key_id' => 'key-1',
        ]);
    })->throws(RuntimeException::class);

    it('throws when active_key_id is missing from keyring map', function () {
        $validKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

        DestinationKeyring::fromConfig([
            'keyring' => json_encode(['key-1' => $validKey]),
            'active_key_id' => 'key-2',
        ]);
    })->throws(RuntimeException::class);

    it('throws when a key is not 32 bytes after base64 decode', function () {
        // 16 bytes only
        $shortKey = base64_encode(str_repeat("\x00", 16));

        DestinationKeyring::fromConfig([
            'keyring' => json_encode(['key-1' => $shortKey]),
            'active_key_id' => 'key-1',
        ]);
    })->throws(RuntimeException::class);

    it('throws when keyFor is called with an unknown key_id', function () {
        $validKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
        $keyring = DestinationKeyring::fromConfig([
            'keyring' => json_encode(['key-1' => $validKey]),
            'active_key_id' => 'key-1',
        ]);

        $keyring->keyFor('unknown-key-id');
    })->throws(RuntimeException::class);

    it('error messages do not contain key material', function () {
        $shortKey = base64_encode(str_repeat("\x00", 16));

        try {
            DestinationKeyring::fromConfig([
                'keyring' => json_encode(['key-1' => $shortKey]),
                'active_key_id' => 'key-1',
            ]);
        } catch (RuntimeException $e) {
            expect($e->getMessage())->not->toContain($shortKey);

            return;
        }

        throw new RuntimeException('Expected RuntimeException was not thrown.');
    });
});
