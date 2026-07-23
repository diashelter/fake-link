<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\AuthTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;

/**
 * @extends Factory<AuthTokenModel>
 */
final class AuthTokenModelFactory extends Factory
{
    protected $model = AuthTokenModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hasher = new Sha256TokenHasher;
        $plainText = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [
            'id' => (string) Str::uuid7(),
            'user_id' => UserModel::factory(),
            'token_hash' => $hasher->hash($plainText),
            'token_kind' => TokenKind::Session->value,
            'expires_at' => now()->addDays(7),
            'last_used_at' => null,
            'created_at' => now(),
        ];
    }
}
