<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\UserPermission;
use App\Policies\CredentialPolicy;
use Illuminate\Support\Facades\Log;

/**
 * Rolün yetkileri — CANLI okunur.
 *
 * NEDEN CANLI: rol bir "başlangıç kümesi" olsaydı ve yetkiler hesap
 * açılırken kullanıcıya KOPYALANSAYDI kopya eskirdi: rolü düzenleyen kişi
 * ekranda yeni yetkiyi görür, kullanıcılar eski yetkiyle çalışırdı.
 *
 * Rol ne diyorsa o geçerli. Kullanıcının kendi satırları YALNIZCA
 * İSTİSNADIR: rolün vermediğini ekler, verdiğini keser
 * (bkz. UserPermissionResolver).
 *
 * Şablon grup ADIYLA saklanır (taşınabilir olsun diye); burada bir kez
 * kimliğe çevrilir ve istek boyunca hazır durur.
 */
class RolePermissions
{
    /** @var array<string, array<int, bool>> "rol|aksiyon" => (grup id => izin) */
    private array $index = [];

    /** @var array<string, int>|null grup adı => id */
    private ?array $groupIds = null;

    /**
     * Rolün bu aksiyonda izin verdiği kayıtlar.
     *
     * ALL_RECORDS anahtarı "bütün gruplarda" demektir.
     *
     * @return array<int, bool>
     */
    public function map(?Role $role, string $resource, string $action): array
    {
        /*
         * Yöneticinin şablonu YOKTUR: bütün kontrolleri zaten geçer
         * (bkz. AppServiceProvider'daki Gate::before). Şablon tutsaydı, o
         * listede olmayan bir yetki "yönetici bunu yapamaz" gibi görünürdü.
         */
        if ($role === null || $role->is_admin || $resource !== CredentialPolicy::RESOURCE) {
            return [];
        }

        $key = $role->getKey().'|'.$action;

        if (isset($this->index[$key])) {
            return $this->index[$key];
        }

        $tanim = $role->normalizedTemplate($resource);
        $map = [];

        if (in_array($action, (array) $tanim['all'], true)) {
            $map[UserPermission::ALL_RECORDS] = true;
        }

        foreach ((array) $tanim['groups'] as $ad => $aksiyonlar) {
            $id = $this->groupIds()[$ad] ?? null;

            /*
             * Adı bulunamayan satır YETKİ VERMEZ ama sessiz de kalmaz.
             *
             * Bu yönde hata güvenli tarafa düşüyor: bir EK yetki kaybolur,
             * kimse fazladan bir şey görmez. Yine de sessiz kalırsa "neden
             * göremiyorum" sorusunun cevabı aranmaz hâle gelir — grup yeniden
             * adlandırılmış ya da başka bir kurulumdan yedek dönülmüş
             * olabilir. Dışlama tarafında aynı durum TERS yönde tehlikeli
             * olduğu için orada "hepsi" izni hiç verilmiyor (bkz. yukarısı).
             */
            if ($id === null) {
                Log::warning('Rol şablonundaki grup bulunamadı; o satırın yetkisi verilmedi.', [
                    'rol' => $role->slug,
                    'grup' => $ad,
                ]);

                continue;
            }

            if (in_array($action, (array) $aksiyonlar, true)) {
                $map[$id] = true;
            }
        }

        return $this->index[$key] = $map;
    }

    /** Rolün bu kaynakta hiç yetkisi var mı? */
    public function grantsAnything(?Role $role, string $resource, string $action): bool
    {
        return $this->map($role, $resource, $action) !== [];
    }

    public function flush(): void
    {
        $this->index = [];
        $this->groupIds = null;
    }

    /**
     * Grup yeniden adlandırıldı: şablonlar yeni adı öğrensin.
     *
     * Şablon grubu ADIYLA tutuyor. Ad değişince satır "bulunamadı"ya
     * düşüyor ve rolün o gruptaki yetkisi SESSİZCE gidiyordu — yalnızca
     * günlüğe bir uyarı yazılıyordu (bkz. map). Grupların başlık altında
     * toplanmasıyla yeniden adlandırma olağan bir işlem oldu; yetkinin ad
     * değişikliğine dayanması gerekir.
     *
     * Yalnızca değişen anahtar yazılır; aksiyon listelerine dokunulmaz.
     */
    public function grupYenidenAdlandirildi(string $eski, string $yeni): void
    {
        if ($eski === $yeni) {
            return;
        }

        $this->sablonlariYenidenYaz(fn (string $ad): ?string => $ad === $eski ? $yeni : $ad);
    }

    /**
     * Grup silindi: şablondaki satırı da gitsin.
     *
     * Satır kalınca yetki vermiyor (grup yok) ama her istekte "şablondaki
     * grup bulunamadı" uyarısı yazıyor; bir grup silindikten sonra günlük
     * bununla doluyordu. Sessizce temizlenir; yetki değişmez.
     */
    public function grupSilindi(string $ad): void
    {
        $this->sablonlariYenidenYaz(fn (string $mevcut): ?string => $mevcut === $ad ? null : $mevcut);
    }

    /**
     * Her rolün şablonundaki grup anahtarlarını dönüştürür; null dönen
     * anahtar silinir. Yalnızca değişen rol kaydedilir.
     *
     * @param  callable(string): ?string  $donustur
     */
    private function sablonlariYenidenYaz(callable $donustur): void
    {
        Role::query()->each(function (Role $role) use ($donustur): void {
            $sablon = $role->template ?? [];
            $gruplar = $sablon[CredentialPolicy::RESOURCE]['groups'] ?? null;

            if (! is_array($gruplar)) {
                return;
            }

            $yeniGruplar = [];

            foreach ($gruplar as $ad => $aksiyonlar) {
                $yeniAd = $donustur((string) $ad);

                if ($yeniAd !== null) {
                    $yeniGruplar[$yeniAd] = $aksiyonlar;
                }
            }

            if ($yeniGruplar === $gruplar) {
                return;
            }

            $sablon[CredentialPolicy::RESOURCE]['groups'] = $yeniGruplar;
            $role->template = $sablon;
            $role->save();
        });

        $this->flush();
    }

    /** @return array<string, int> */
    private function groupIds(): array
    {
        return $this->groupIds ??= CredentialGroup::query()->pluck('id', 'name')->all();
    }
}
