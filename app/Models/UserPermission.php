<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tek bir yetki satırı: "şu kullanıcı, şu grupta, şu aksiyonu yapabilir/yapamaz".
 *
 * Alan görünürlüğü de bir aksiyondur ('field.password' gibi): "parolayı
 * görebilir" ile "adresi görebilir" ayrı satırlarda durur.
 */
class UserPermission extends Model
{
    /** resource_id bu değere eşitse izin, kaynağın TAMAMINI kapsar. */
    public const ALL_RECORDS = 0;

    protected $fillable = [
        'user_id',
        'resource',
        'resource_id',
        'action',
        'effect',
    ];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
            'effect' => PermissionEffect::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeWildcard(Builder $query): void
    {
        $query->where('resource_id', self::ALL_RECORDS);
    }
}
