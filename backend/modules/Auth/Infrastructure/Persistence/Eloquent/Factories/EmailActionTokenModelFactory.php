<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Auth\Domain\Enums\EmailActionPurpose;
use Modules\Auth\Infrastructure\Hashing\Sha256TokenHasher;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\EmailActionTokenModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;

/**
 * @extends Factory<EmailActionTokenModel>
 */
final class EmailActionTokenModelFactory extends Factory
{
    protected $model = EmailActionTokenModel::class;

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
            'purpose' => EmailActionPurpose::EmailVerification->value,
            'expires_at' => now()->addHour(),
            'used_at' => null,
            'created_at' => now(),
        ];
    }
}
