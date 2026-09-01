<?php

declare(strict_types=1);

use Modules\Links\Contracts\Services\RandomSlugSource;
use Modules\Links\Domain\Enums\SlugRejectionReason;
use Modules\Links\Domain\Enums\SlugSource;
use Modules\Links\Domain\Services\SlugGenerator;
use Modules\Links\Domain\Services\SlugPolicy;
use Modules\Links\Exceptions\SlugGenerationExhausted;
use Modules\Links\Exceptions\SlugPolicyException;
use Modules\Links\Infrastructure\Slug\ConfigReservedSlugs;
use Modules\Links\Infrastructure\Slug\CsprngSlugSource;

/**
 * A RandomSlugSource that replays a fixed script and counts its calls.
 */
final class ScriptedSlugSource implements RandomSlugSource
{
    public int $calls = 0;

    /** @param  list<string>  $sequence */
    public function __construct(private array $sequence) {}

    public function candidate(int $length, string $alphabet): string
    {
        $value = $this->sequence[$this->calls] ?? throw new RuntimeException('scripted sequence exhausted');

        $this->calls++;

        return $value;
    }
}

/**
 * @param  list<string>  $denylist
 */
function generatorWith(RandomSlugSource $source, array $denylist = [], int $length = 8, int $maxDenylistDiscards = 5): SlugGenerator
{
    return new SlugGenerator($source, new SlugPolicy(new ConfigReservedSlugs($denylist)), $length, $maxDenylistDiscards);
}

describe('SlugGenerator::generate', function () {
    it('returns an automatic-source Slug of exactly 8 [a-z0-9] characters', function () {
        $slug = generatorWith(new CsprngSlugSource)->generate();

        expect($slug->source())->toBe(SlugSource::Automatic)
            ->and($slug->value())->toMatch('/^[a-z0-9]{8}$/');
    });

    it('stays within [a-z0-9]{8} across many generations with the real CSPRNG source', function () {
        $generator = generatorWith(new CsprngSlugSource);

        for ($i = 0; $i < 500; $i++) {
            expect($generator->generate()->value())->toMatch('/^[a-z0-9]{8}$/');
        }
    });

    it('discards a reserved candidate and regenerates the next one', function () {
        $source = new ScriptedSlugSource(['reserved', 'freeslug']);

        $slug = generatorWith($source, ['reserved'])->generate();

        expect($slug->value())->toBe('freeslug')
            ->and($source->calls)->toBe(2);
    });

    it('propagates a non-reserved structural failure instead of discarding it', function () {
        $source = new ScriptedSlugSource(['short']);

        try {
            generatorWith($source, [])->generate();
            throw new RuntimeException('expected SlugPolicyException');
        } catch (SlugPolicyException $exception) {
            expect($exception->rejectionReason())->toBe(SlugRejectionReason::TooShort);
        }
    });

    it('fails with SlugGenerationExhausted after 5 consecutive denylist discards', function () {
        $reserved = ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'eeeeeeee'];
        $source = new ScriptedSlugSource($reserved);

        expect(fn () => generatorWith($source, $reserved)->generate())
            ->toThrow(SlugGenerationExhausted::class);
    });

    it('requests exactly 5 candidates before giving up, never a sixth', function () {
        $reserved = ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'eeeeeeee'];
        $source = new ScriptedSlugSource($reserved);

        try {
            generatorWith($source, $reserved)->generate();
        } catch (SlugGenerationExhausted) {
            // expected
        }

        expect($source->calls)->toBe(5);
    });

    it('succeeds on the fifth attempt when the first four are reserved', function () {
        $source = new ScriptedSlugSource(['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'freeslug']);

        $slug = generatorWith($source, ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd'])->generate();

        expect($slug->value())->toBe('freeslug')
            ->and($source->calls)->toBe(5);
    });

    it('honours a custom discard ceiling', function () {
        $reserved = ['aaaaaaaa', 'bbbbbbbb'];
        $source = new ScriptedSlugSource($reserved);

        expect(fn () => generatorWith($source, $reserved, 8, 2)->generate())
            ->toThrow(SlugGenerationExhausted::class);
        expect($source->calls)->toBe(2);
    });

    it('does not depend on a repository or database — constructor takes only the random source, policy and two ints', function () {
        $parameters = (new ReflectionMethod(SlugGenerator::class, '__construct'))->getParameters();
        $types = array_map(
            static fn (ReflectionParameter $p): string => $p->getType() instanceof ReflectionNamedType
                ? $p->getType()->getName()
                : '',
            $parameters,
        );

        expect($types)->toBe([
            RandomSlugSource::class,
            SlugPolicy::class,
            'int',
            'int',
        ]);
    });
});
