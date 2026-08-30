<?php

declare(strict_types=1);

namespace Modules\Links\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Links\Infrastructure\Persistence\Eloquent\Factories\ShortLinkModelFactory;

/**
 * @property string $id
 * @property string $user_id
 * @property string $slug
 * @property string $slug_source
 * @property string|null $title
 * @property bool $is_enabled
 * @property Carbon|null $blocked_at
 * @property Carbon|null $expires_at
 * @property int $version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ShortLinkModel extends Model
{
    /** @use HasFactory<ShortLinkModelFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'short_links';

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'slug',
        'slug_source',
        'title',
        'is_enabled',
        'blocked_at',
        'expires_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'blocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function newFactory(): ShortLinkModelFactory
    {
        return ShortLinkModelFactory::new();
    }
}
