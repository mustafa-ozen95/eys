<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BadgeVariant;
use App\Models\Concerns\HasUuid;
use App\Policies\CredentialPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panel rolü.
 *
 * Rol tek başına yetki DEĞİLDİR; hazır bir yetki şablonudur. Şablon
 * uygulandıktan sonra kişiye özel istisnalar yetki ekranından verilir —
 * "srv-a hariç her grupta parolayı görsün" gibi.
 *
 * Yönetici rolü (`is_admin`) bunun dışındadır: bütün kontrolleri geçer.
 * En az bir yönetici rolü KALMAK ZORUNDA; yoksa paneli kimse yönetemez.
 *
 * Yerleşik roller (`is_system`) silinemez ve slug'ı değişmez: seeder, test
 * ve şablon dosyaları onlara adıyla atıfta bulunuyor.
 */
class Role extends Model
{
    use HasUuid;

    protected $fillable = [
        'slug',
        'label',
        'description',
        'badge',
        'is_admin',
        'is_default',
        'template',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'badge' => BadgeVariant::class,
            'is_admin' => 'boolean',
            'is_default' => 'boolean',
            'is_system' => 'boolean',
            'template' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Rolün hazır yetki şablonu: kaynak => ['all' => [...], 'groups' => [...]].
     *
     * @return array<string, array<string, mixed>>
     */
    public function abilities(): array
    {
        return $this->template ?? [];
    }

    /**
     * Şablonun ekranda kullanılan biçimi.
     *
     * Kayıtta 'all'/'groups' anahtarları eksik olabilir (elle düzenlenmiş ya
     * da eski biçimde kaydedilmiş rol); form her ikisinin de var olduğunu
     * varsayabilsin diye tamamlanır.
     *
     * @return array{all: list<string>, groups: array<string, list<string>>}
     */
    public function normalizedTemplate(string $resource = CredentialPolicy::RESOURCE): array
    {
        $tanim = $this->abilities()[$resource] ?? [];

        return [
            'all' => array_values((array) ($tanim['all'] ?? [])),
            'groups' => (array) ($tanim['groups'] ?? []),
        ];
    }

    /**
     * Liste ekranındaki tek satırlık özet.
     *
     * @return Attribute<string, never>
     */
    protected function templateSummary(): Attribute
    {
        return Attribute::get(function (): string {
            $tanim = $this->normalizedTemplate();
            $labels = config('permissions.actions', []);

            $genel = collect($tanim['all'])->map(fn (string $a): string => $labels[$a] ?? $a);
            $ek = count($tanim['groups']);

            return match (true) {
                $genel->isEmpty() && $ek === 0 => 'tanımlı değil',
                $ek === 0 => $genel->implode(', '),
                default => $genel->implode(', ')." (+{$ek} grupta ek yetki)",
            };
        });
    }

    /**
     * Rol silinebilir mi?
     *
     * Yerleşik roller ve kullanıcısı olan roller silinemez: kullanıcı rolsüz
     * kalırsa hiçbir kontrolden geçemez.
     */
    public function isDeletable(): bool
    {
        return ! $this->is_system && $this->users()->count() === 0;
    }

    /**
     * Yeni kullanıcının varsayılan rolü.
     *
     * Yönetici rolü varsayılan OLAMAZ — "kullanıcı ekle" yetkisi, yönetici
     * üretme yetkisi değildir.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('is_admin', false)->first()
            ?? static::query()->where('is_admin', false)->ordered()->first();
    }

    /** @param  Builder<$this>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('label');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
