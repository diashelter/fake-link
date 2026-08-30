<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Links\Infrastructure\Persistence\Eloquent\Factories\LinkDestinationVersionModelFactory;

/**
 * @property string $id
 * @property string $short_link_id
 * @property string $destination_url
 * @property string $key_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 */
final class LinkDestinationVersionModel extends Model
{
    /** @use HasFactory<LinkDestinationVersionModelFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'link_destination_versions';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'short_link_id',
        'destination_url',
        'key_id',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    protected static function newFactory(): LinkDestinationVersionModelFactory
    {
        return LinkDestinationVersionModelFactory::new();
    }
}
