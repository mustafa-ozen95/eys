<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use App\Models\Concerns\ClosesSessions;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Yetkilendirme mantığı bilinçli olarak bu modelde DEĞİLDİR;
 * App\Services\Authorization\UserPermissionResolver sınıfında toplanır.
 */
class User extends Authenticatable
{
    use ClosesSessions;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Rol ve yetki satırları her istekte okunur: isAdmin() bütün yetki
     * kontrollerinin ilk sorusudur, yetki satırları da her kutucukta.
     * Eager yüklenmezse liste ekranı satır başına sorgu atardı.
     *
     * @var list<string>
     */
    protected $with = ['role', 'permissions'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * Kullanıcının rolü.
     *
     * Rol bir KAYITTIR, enum değil: panelden yeni rol açılabilsin ve hazır
     * yetkileri panelden düzenlenebilsin diye.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<UserPermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Bütün yetki kontrollerini geçer mi? (bkz. AppServiceProvider, Gate::before)
     *
     * Rolü okunamayan kullanıcı yönetici SAYILMAZ ve pasif hesap da öyle:
     * eksik veri yetki üretmemeli.
     */
    public function isAdmin(): bool
    {
        return $this->role?->is_admin === true && $this->isActive();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active);
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$value}%")
                ->orWhere('email', 'like', "%{$value}%")
        ));
    }
}
