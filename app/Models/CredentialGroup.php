<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GroupKind;
use App\Models\Concerns\HasUuid;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Database\Factories\CredentialGroupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Erişim grubu — bir sistem ya da varlık (srv-a, Grup A, Grup B...).
 *
 * Yetki bu seviyede verilir: kullanıcıya kayıt kayıt izin vermek yerine grup
 * verilir. Yeni bir kayıt eklendiğinde izin listesini güncellemek gerekmez;
 * grubu gören yeni kaydı da görür.
 */
class CredentialGroup extends Model
{
    /** @use HasFactory<CredentialGroupFactory> */
    use HasFactory;

    use HasUuid;

    protected $fillable = [
        'name',
        'kind',
        'description',
        'position',
        'is_personal',
    ];

    protected function casts(): array
    {
        return [
            'kind' => GroupKind::class,
            'position' => 'integer',
            'is_personal' => 'boolean',
        ];
    }

    /** @return HasMany<Credential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class)->ordered();
    }

    /**
     * Grubun bölmeleri — kenar çubuğunda grubun altında, listede başlık.
     *
     * Yetki taşımazlar; grup neyse o (bkz. AltGrup).
     *
     * @return HasMany<AltGrup, $this>
     */
    public function altGruplar(): HasMany
    {
        return $this->hasMany(AltGrup::class)->ordered();
    }

    /**
     * Kullanıcının görebildiği gruplar.
     *
     * "Hepsini getir sonra ekranda gizle" güvenlik değildir: daraltma
     * SORGUDA yapılır. Yönetici için daraltma yoktur.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        /*
         * YÖNETİCİ AYRI ELE ALINIR ve bu şekilcilik değil.
         *
         * Çözümleyicinin kapsamı yöneticide HİÇBİR KOŞUL EKLEMEZ. O koşulsuz
         * dalı bir "veya" grubunun içine koyunca grup boş kalır, Laravel
         * boş grubu atar ve yönetici bütün grupları kaybederdi.
         */
        if ($user->isAdmin()) {
            app(UserPermissionResolver::class)->scope($query, $user, CredentialPolicy::RESOURCE, 'view');

            return;
        }

        $query->where(function (Builder $dis) use ($user): void {
            $dis->where(fn (Builder $ic) => app(UserPermissionResolver::class)
                ->scope($ic, $user, CredentialPolicy::RESOURCE, 'view'))
                /*
                 * Grubu göremeyen ama içinde tek kayda görme izni olan rol:
                 * grup kenar çubuğunda yalnız o kayıtla görünür (sayı da
                 * Credential::scopeVisibleTo'dan gelir). Kısıtlı kayıt saymaz.
                 */
                ->orWhereHas('credentials', fn (Builder $kayit) => $kayit
                    ->rolunGormeIzniVar($user)
                    ->whereNotRestrictedFor($user));
        });
    }

    /** @param  Builder<$this>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name');
    }

    /** @param  Builder<$this>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $q, string $value) => $q->where(
            fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%")
        ));
    }
}
