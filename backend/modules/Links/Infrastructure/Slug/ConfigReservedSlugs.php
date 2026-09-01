<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Slug;

use Modules\Links\Contracts\Services\ReservedSlugs;

/**
 * Reads the denylist from config('links.slug.reserved_words') and indexes it
 * for exact-match lookups. An explicit list may be injected instead, in which
 * case config() is never touched. An empty list is valid configuration.
 */
final class ConfigReservedSlugs implements ReservedSlugs
{
    /** @var array<string, true> */
    private readonly array $index;

    /**
     * @param  list<string>|null  $reservedWords
     */
    public function __construct(?array $reservedWords = null)
    {
        $words = $reservedWords ?? self::fromConfig();

        $index = [];

        foreach ($words as $word) {
            $index[$word] = true;
        }

        $this->index = $index;
    }

    public function contains(string $normalizedSlug): bool
    {
        return isset($this->index[$normalizedSlug]);
    }

    /**
     * @return list<string>
     */
    private static function fromConfig(): array
    {
        /** @var mixed $configured */
        $configured = config('links.slug.reserved_words', []);

        if (! is_array($configured)) {
            return [];
        }

        $words = [];

        foreach ($configured as $entry) {
            if (is_string($entry)) {
                $words[] = $entry;
            }
        }

        return $words;
    }
}
