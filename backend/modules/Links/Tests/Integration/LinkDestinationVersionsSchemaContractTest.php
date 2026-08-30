<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Links\Domain\ValueObjects\DestinationUrl;
use Modules\Links\Domain\ValueObjects\EncryptedDestination;
use Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher;
use Modules\Links\Infrastructure\Crypto\DestinationKeyring;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

function makeShortLink(): ShortLinkModel
{
    $userId = (string) Str::uuid7();
    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Test User',
        'email' => 'ldv-user-'.Str::random(8).'@example.com',
        'password' => 'hash',
        'status' => 'pending_verification',
        'terms_version' => '2026-01',
        'terms_accepted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reservation = SlugReservationModel::create([
        'slug' => Str::random(8),
        'reserved_at' => now(),
    ]);

    return ShortLinkModel::create([
        'id' => (string) Str::uuid7(),
        'user_id' => $userId,
        'slug' => $reservation->slug,
        'slug_source' => 'automatic',
        'is_enabled' => true,
        'version' => 1,
    ]);
}

describe('link_destination_versions schema contract', function () {
    it('has correct column types', function () {
        $columns = DB::select(
            "SELECT column_name, data_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'link_destination_versions'
             ORDER BY ordinal_position"
        );

        $columnMap = collect($columns)->keyBy('column_name');

        expect($columnMap->get('id')->data_type)->toBe('uuid')
            ->and($columnMap->get('short_link_id')->data_type)->toBe('uuid')
            ->and($columnMap->get('destination_url')->data_type)->toBe('text')
            ->and($columnMap->get('key_id')->data_type)->toBe('character varying')
            ->and($columnMap->get('valid_from')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('valid_to')->data_type)->toBe('timestamp with time zone')
            ->and($columnMap->get('valid_to')->is_nullable)->toBe('YES');
    });

    it('enforces partial unique index: two open versions of same short_link fail', function () {
        $link = makeShortLink();

        DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link->id,
            'destination_url' => 'enc-value-1',
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => null,
        ]);

        expect(fn () => DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link->id,
            'destination_url' => 'enc-value-2',
            'key_id' => 'testing-key-1',
            'valid_from' => now()->addSecond(),
            'valid_to' => null,
        ]))->toThrow(QueryException::class);
    });

    it('allows two open versions for different short_links', function () {
        $link1 = makeShortLink();
        $link2 = makeShortLink();

        DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link1->id,
            'destination_url' => 'enc-value-1',
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => null,
        ]);

        DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link2->id,
            'destination_url' => 'enc-value-2',
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => null,
        ]);

        expect(LinkDestinationVersionModel::count())->toBe(2);
    });

    it('rejects valid_to <= valid_from (CHECK constraint)', function () {
        $link = makeShortLink();

        expect(fn () => DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link->id,
            'destination_url' => 'enc-value',
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => now()->subSecond(),
        ]))->toThrow(QueryException::class);
    });

    it('accepts valid_to > valid_from', function () {
        $link = makeShortLink();

        DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link->id,
            'destination_url' => 'enc-value',
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => now()->addHour(),
        ]);

        expect(LinkDestinationVersionModel::count())->toBe(1);
    });

    it('restricts deletion of short_link referenced by link_destination_version', function () {
        $link = makeShortLink();

        DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link->id,
            'destination_url' => 'enc-value',
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => null,
        ]);

        expect(fn () => $link->delete())->toThrow(QueryException::class);
    });

    it('has ldv_history_idx index on (short_link_id, valid_from)', function () {
        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes
             WHERE tablename = 'link_destination_versions' AND indexname = 'ldv_history_idx'"
        );

        expect($indexes)->not->toBeEmpty();
    });

    it('stores encrypted destination and decrypts back correctly without plaintext in stored value', function () {
        $link = makeShortLink();

        $keyring = DestinationKeyring::fromConfig(config('links.destination'));
        $cipher = new Aes256GcmDestinationCipher($keyring);

        $originalUrl = 'https://example.com/secret-destination';
        $destinationUrl = DestinationUrl::fromString($originalUrl);
        $encrypted = $cipher->encrypt($destinationUrl);

        DB::table('link_destination_versions')->insert([
            'id' => (string) Str::uuid7(),
            'short_link_id' => $link->id,
            'destination_url' => $encrypted->envelope(),
            'key_id' => $encrypted->keyId(),
            'valid_from' => now(),
            'valid_to' => null,
        ]);

        $row = DB::table('link_destination_versions')
            ->where('short_link_id', $link->id)
            ->first();

        // Stored value must not contain the plaintext URL
        expect($row->destination_url)->not->toContain($originalUrl);

        // Round-trip: decrypt back to original URL
        $retrieved = EncryptedDestination::fromParts($row->destination_url, $row->key_id);
        $decrypted = $cipher->decrypt($retrieved);

        expect($decrypted->value())->toBe($originalUrl);
    });
});
