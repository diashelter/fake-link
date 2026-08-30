<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\ShortLinkModel;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;

/**
 * @extends Factory<ShortLinkModel>
 */
final class ShortLinkModelFactory extends Factory
{
    protected $model = ShortLinkModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reservation = SlugReservationModel::factory()->create();

        return [
            'id' => (string) Str::uuid7(),
            'user_id' => (string) Str::uuid7(),
            'slug' => $reservation->slug,
            'slug_source' => 'automatic',
            'title' => null,
            'is_enabled' => true,
            'blocked_at' => null,
            'expires_at' => null,
            'version' => 1,
        ];
    }

    public function withUserId(string $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId]);
    }
}
