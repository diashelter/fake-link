<?php

declare(strict_types=1);

use Modules\Links\Infrastructure\Slug\CsprngSlugSource;

function base36Alphabet(): string
{
    return 'abcdefghijklmnopqrstuvwxyz0123456789';
}

describe('CsprngSlugSource', function () {
    it('returns a string of exactly the requested length', function () {
        expect(strlen((new CsprngSlugSource)->candidate(8, base36Alphabet())))->toBe(8);
    });

    it('honours a different requested length rather than a fixed one', function () {
        expect(strlen((new CsprngSlugSource)->candidate(12, base36Alphabet())))->toBe(12);
    });

    it('draws every character from the given alphabet', function () {
        $candidate = (new CsprngSlugSource)->candidate(64, base36Alphabet());

        expect(preg_match('/^['.preg_quote(base36Alphabet(), '/').']+$/', $candidate))->toBe(1);
    });

    it('produces no repetition across 10,000 generations', function () {
        $source = new CsprngSlugSource;
        $seen = [];

        for ($i = 0; $i < 10000; $i++) {
            $seen[$source->candidate(8, base36Alphabet())] = true;
        }

        expect(count($seen))->toBe(10000);
    });

    it('reaches all 36 alphabet symbols across 10,000 generations (no modulo bias)', function () {
        $source = new CsprngSlugSource;
        $reached = [];

        for ($i = 0; $i < 10000; $i++) {
            foreach (str_split($source->candidate(8, base36Alphabet())) as $char) {
                $reached[$char] = true;
            }
        }

        expect(count($reached))->toBe(36);
    });

    it('uses only random_int as its entropy source', function () {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/Infrastructure/Slug/CsprngSlugSource.php'
        );

        expect($source)->toContain('random_int(')
            ->and($source)->not->toContain('mt_rand')
            ->and($source)->not->toContain('str_shuffle')
            ->and($source)->not->toContain('shuffle(')
            ->and($source)->not->toContain('uniqid')
            ->and($source)->not->toMatch('/\brand\s*\(/')
            ->and($source)->not->toContain('microtime')
            ->and($source)->not->toContain('% strlen')
            ->and($source)->not->toContain('random_bytes');
    });
});
