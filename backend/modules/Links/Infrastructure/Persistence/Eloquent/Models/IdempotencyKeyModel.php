<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $user_id
 * @property string $key_hash
 * @property string $request_fingerprint
 * @property string|resource|null $response_snapshot
 * @property string|null $key_id
 * @property Carbon $created_at
 * @property Carbon $expires_at
 */
final class IdempotencyKeyModel extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'idempotency_keys';

    protected $primaryKey = null;

    protected $fillable = [
        'user_id',
        'key_hash',
        'request_fingerprint',
        'response_snapshot',
        'key_id',
        'created_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
