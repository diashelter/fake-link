<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Links\Infrastructure\Persistence\Eloquent\Factories\SlugReservationModelFactory;

/**
 * @property string $slug
 * @property Carbon $reserved_at
 */
final class SlugReservationModel extends Model
{
    /** @use HasFactory<SlugReservationModelFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'slug_reservations';

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    protected $fillable = [
        'slug',
        'reserved_at',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SlugReservationModelFactory
    {
        return SlugReservationModelFactory::new();
    }
}
