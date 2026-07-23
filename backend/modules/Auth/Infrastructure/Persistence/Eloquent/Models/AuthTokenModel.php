<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Factories\AuthTokenModelFactory;

/**
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property string $token_kind
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 */
final class AuthTokenModel extends Model
{
    /** @use HasFactory<AuthTokenModelFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'auth_tokens';

    protected $fillable = [
        'id',
        'user_id',
        'token_hash',
        'token_kind',
        'expires_at',
        'last_used_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    protected static function newFactory(): AuthTokenModelFactory
    {
        return AuthTokenModelFactory::new();
    }
}
