<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Factories\EmailActionTokenModelFactory;

/**
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property string $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon $created_at
 */
final class EmailActionTokenModel extends Model
{
    /** @use HasFactory<EmailActionTokenModelFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'email_action_tokens';

    protected $fillable = [
        'id',
        'user_id',
        'token_hash',
        'purpose',
        'expires_at',
        'used_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
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

    protected static function newFactory(): EmailActionTokenModelFactory
    {
        return EmailActionTokenModelFactory::new();
    }
}
