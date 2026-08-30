<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\SlugReservationModel;

/**
 * @extends Factory<SlugReservationModel>
 */
final class SlugReservationModelFactory extends Factory
{
    protected $model = SlugReservationModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->lexify('????????'),
            'reserved_at' => now(),
        ];
    }
}
