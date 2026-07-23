<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

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
}
