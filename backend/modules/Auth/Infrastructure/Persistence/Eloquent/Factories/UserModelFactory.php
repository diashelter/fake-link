<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Domain\Enums\UserStatus;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;

/**
 * @extends Factory<UserModel>
 */
final class UserModelFactory extends Factory
{
    protected $model = UserModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'name' => fake()->name(),
            'email' => strtolower(fake()->unique()->safeEmail()),
            'password' => '$argon2id$v=19$m=65536,t=4,p=1$fixture',
            'status' => UserStatus::PendingVerification->value,
            'email_verified_at' => null,
            'terms_version' => '2026-01',
            'terms_accepted_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::Active->value,
            'email_verified_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::Suspended->value,
        ]);
    }

    public function deletionPending(): static
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::DeletionPending->value,
        ]);
    }

    public function withPassword(string $plain): static
    {
        return $this->state(fn (): array => [
            'password' => Hash::make($plain),
        ]);
    }
}
