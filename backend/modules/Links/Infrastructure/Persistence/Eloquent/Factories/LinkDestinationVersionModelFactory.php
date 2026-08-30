<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;

/**
 * @extends Factory<LinkDestinationVersionModel>
 */
final class LinkDestinationVersionModelFactory extends Factory
{
    protected $model = LinkDestinationVersionModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'short_link_id' => (string) Str::uuid7(),
            'destination_url' => fake()->url(),
            'key_id' => 'testing-key-1',
            'valid_from' => now(),
            'valid_to' => null,
        ];
    }

    public function withShortLinkId(string $shortLinkId): static
    {
        return $this->state(fn (): array => ['short_link_id' => $shortLinkId]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'valid_to' => now()->addHour(),
        ]);
    }
}
