<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Factories\UserModelFactory;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $terms_version
 * @property \Illuminate\Support\Carbon $terms_accepted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class UserModel extends Model
{
    /** @use HasFactory<UserModelFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'users';

    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
        'status',
        'email_verified_at',
        'terms_version',
        'terms_accepted_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserModelFactory
    {
        return UserModelFactory::new();
    }
}
