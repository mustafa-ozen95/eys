<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Guncelleme\GuncellemeDenetimi;
use Illuminate\Console\Command;

/**
 * Panelin yeni sürümü var mı: GitHub'daki son başarılı imaj derlemesiyle
 * karşılaştırır. YALNIZCA BAKAR; güncellemeyi sunucudan yapın.
 *
 * Zamanlayıcı her sabah çağırır; sonuç Ayarlar → Güncelleme'de ve yeni
 * sürüm varsa yöneticinin uyarı şeridinde görünür. Elle de çalıştırılabilir:
 *
 *     php artisan guncelleme:denetle
 */
class GuncellemeDenetleCommand extends Command
{
    protected $signature = 'guncelleme:denetle';

    protected $description = 'Yeni sürüm var mı: GitHub\'daki son başarılı imaj derlemesiyle karşılaştırır (yalnızca bakar)';

    public function handle(GuncellemeDenetimi $denetim): int
    {
        $sonuc = $denetim->denetle('günlük tur');

        $ileti = $sonuc['durum'].': '.($sonuc['aciklama'] ?? '');
        $sonuc['durum'] === 'hata' ? $this->components->error($ileti) : $this->components->info($ileti);

        return $sonuc['durum'] === 'hata' ? self::FAILURE : self::SUCCESS;
    }
}
