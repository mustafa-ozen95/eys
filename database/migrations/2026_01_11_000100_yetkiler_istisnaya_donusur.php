<?php

declare(strict_types=1);

use App\Enums\PermissionEffect;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\RolePermissions;
use Illuminate\Database\Migrations\Migration;

/**
 * Kullanıcı yetkileri artık İSTİSNA.
 *
 * Rol eskiden bir "başlangıç kümesi"ydi: yetkiler hesap açılırken kullanıcıya
 * KOPYALANIYORDU. Kopya eskiyordu — rolü düzenleyen ekranda yeni yetkiyi
 * görüyor, kullanıcılar eski yetkiyle çalışıyordu ve düzeltmenin yolu tek tek
 * "şablonu uygula" demekti. Artık rol canlı okunuyor
 * (bkz. RolePermissions) ve kullanıcının satırları yalnızca istisna tutuyor.
 *
 * BU GÖÇ KİMSENİN ERİŞİMİNİ DEĞİŞTİRMEZ. Elimizdeki satırlar bir zamanlar
 * kopyalanmış şablonlar; bugün geçerli olan erişimi olduğu gibi korumak için:
 *
 *   - Rolün verdiği ama kullanıcıda OLMAYAN yetki  -> açık YASAK satırı
 *     (yoksa göç, kimsenin vermediği bir yetkiyi açardı)
 *   - Kullanıcıda olan ama rolün vermediği yetki   -> İZİN satırı olarak kalır
 *   - İkisinde de olan                             -> satır silinir (gereksiz)
 *
 * Yönetici rolündekilere dokunulmaz: onlar zaten bütün kontrolleri geçiyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roller = app(RolePermissions::class);
        $kaynak = CredentialPolicy::RESOURCE;
        $aksiyonlar = (array) config('permissions.resources.'.$kaynak.'.actions', []);

        User::query()->with(['role', 'permissions'])->chunkById(200, function ($kullanicilar) use ($roller, $kaynak, $aksiyonlar): void {
            foreach ($kullanicilar as $kullanici) {
                if ($kullanici->role?->is_admin) {
                    continue;
                }

                $this->tasi($kullanici, $roller, $kaynak, $aksiyonlar);
            }
        });
    }

    /**
     * Geri alınabilir DEĞİL — ama zararsız.
     *
     * Eski model kopyaya dayanıyordu; kopyayı geri üretmek, bu göçten sonra
     * verilmiş istisnaları da şablona karıştırmak olurdu. Geri dönüş gerekirse
     * roller ekranından şablon, kullanıcı ekranından istisna yeniden kurulur.
     */
    public function down(): void
    {
        // Kasıtlı olarak boş.
    }

    /**
     * @param  list<string>  $aksiyonlar
     */
    private function tasi(User $kullanici, RolePermissions $roller, string $kaynak, array $aksiyonlar): void
    {
        /** @var array<string, array<int, PermissionEffect>> $mevcut */
        $mevcut = [];

        foreach ($kullanici->permissions->where('resource', $kaynak) as $satir) {
            $mevcut[$satir->action][$satir->resource_id] = $satir->effect;
        }

        $yeni = [];

        foreach ($aksiyonlar as $aksiyon) {
            $rol = $roller->map($kullanici->role, $kaynak, $aksiyon);
            $kisi = $mevcut[$aksiyon] ?? [];

            /* Kullanıcıda olan, rolde olmayan izinler: istisna olarak kalır. */
            foreach ($kisi as $kayit => $etki) {
                if ($etki === PermissionEffect::Deny) {
                    $yeni[] = [$kayit, $aksiyon, PermissionEffect::Deny];

                    continue;
                }

                if (! isset($rol[$kayit])) {
                    $yeni[] = [$kayit, $aksiyon, PermissionEffect::Allow];
                }
            }

            /* Rolün verdiği ama kullanıcıda olmayanlar: açık YASAK. */
            foreach (array_keys($rol) as $kayit) {
                if (! isset($kisi[$kayit])) {
                    $yeni[] = [$kayit, $aksiyon, PermissionEffect::Deny];
                }
            }
        }

        $kullanici->permissions()->where('resource', $kaynak)->delete();

        foreach ($yeni as [$kayit, $aksiyon, $etki]) {
            UserPermission::query()->create([
                'user_id' => $kullanici->getKey(),
                'resource' => $kaynak,
                'resource_id' => $kayit,
                'action' => $aksiyon,
                'effect' => $etki,
            ]);
        }
    }
};
