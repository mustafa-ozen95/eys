<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessType;
use App\Enums\GroupKind;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\CredentialGroup;
use Illuminate\Database\Seeder;

/**
 * Erişim kasasının İSKELETİ.
 *
 * VERİ DEPODA DURMAZ. Grup ve kayıt adları sunucu envanterini satır satır
 * anlatır; parola olmasa da "hangi makineler var, hangi panelleri koşuyorlar"
 * bilgisi tek başına da kimseye verilmez. Yazılım depoya girer, veri girmez.
 *
 * Liste `database/seeders/yerel/kasa-yapisi.php` içindedir (.gitignore'da);
 * yoksa seeder hiçbir şey yapmaz. Parola, adres ve kullanıcı adı orada da
 * YOKTUR — onlar panelden girilir.
 *
 * Platform alanı sıfırlama otomasyonunun sürücüsünü belirler; bu yüzden
 * tahminle değil, sorup öğrenilerek doldurulur. Bilinmeyen "unknown" kalır —
 * yanlış bir platform, yanlış bir sürücü demektir.
 *
 * Yeniden çalıştırmak güvenlidir: var olan kaydın parolasına ve adresine
 * dokunulmaz, yalnızca sınıflandırma alanları güncellenir.
 *
 * @phpstan-type Kalem array{0: AccessType, 1: OperatingSystem, 2?: string}
 */
class CredentialGroupSeeder extends Seeder
{
    /**
     * Kasa iskeleti YEREL BİR DOSYADAN gelir.
     *
     * @return array<string, array{kind: GroupKind, items: array<string, array>}>
     */
    private function yapi(): array
    {
        $dosya = __DIR__.'/yerel/kasa-yapisi.php';

        if (! is_file($dosya)) {
            return [];
        }

        /** @var array<string, array{kind: GroupKind, items: array<string, array>}> */
        return require $dosya;
    }

    public function run(): void
    {
        $groupPosition = 0;

        $yapi = $this->yapi();

        if ($yapi === []) {
            $this->command?->warn('yerel/kasa-yapisi.php yok; grup eklenmedi.');

            return;
        }

        foreach ($yapi as $name => $tanim) {
            $group = CredentialGroup::updateOrCreate(
                ['name' => $name],
                ['kind' => $tanim['kind'], 'position' => $groupPosition += 10],
            );

            $position = 0;

            foreach ($tanim['items'] as $itemName => $kalem) {
                $position += 10;

                /*
                 * Liste kalemi iki ya da üç elemanlı olabilir: sürüm her kayıt
                 * için bilinmiyor. Doğrudan dizi çözümlemesi ("[$a, $b, $c] =")
                 * üçüncü eleman yokken uyarı üretip seeder'ı yarıda kesiyordu —
                 * kayıtların bir kısmı işlenmiş, bir kısmı işlenmemiş kalıyordu.
                 */
                [$type, $os] = $kalem;
                $platform = $kalem[2] ?? null;

                /*
                 * Sınıflandırma alanları HER çalıştırmada güncellenir (bilgi
                 * netleştikçe burası değişiyor); parola, adres ve kullanıcı adı
                 * yalnızca kayıt ilk açılırken yazılır ve bir daha ezilmez.
                 */
                Credential::updateOrCreate(
                    ['credential_group_id' => $group->getKey(), 'name' => $itemName],
                    [
                        'access_type' => $type,
                        'operating_system' => $os,
                        'platform' => $platform,
                        'position' => $position,
                    ],
                );
            }
        }
    }
}
