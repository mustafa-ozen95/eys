<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OsUpdateState;
use App\Services\OsUpdate\OsUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * Kuyrukta işi kalmamış "sırada / kuruluyor" kayıtlarını düşürür.
 *
 * Kuyruk silinirse (Redis kalıcı değil: sunucu ya da Redis yeniden
 * başlatıldı) ya da işçi öldürülürse iş hiç koşmaz, `failed()` de çağrılmaz.
 * Kayıt "sırada"da asılı kalır; asılı kayıt meşgul sayıldığı için makine bir
 * daha SEÇİLEMEZ (bkz. OsUpdateState::isBusy). 16.11.2026'da 13 makine böyle
 * kaldı.
 *
 * YALNIZCA kuyruk BOMBOŞKEN düşürür: bekleyen, gecikmeli ya da işçide süren
 * (reserved) tek bir iş varsa hiçbir kayda dokunmaz; o kaydın işi o olabilir.
 * Sıraya alma ile kuyruğa yazma arasındaki an yarışmasın diye son iki dakikada
 * değişen kayda da dokunulmaz.
 */
class OsUpdateReconcileCommand extends Command
{
    protected $signature = 'os-update:reconcile';

    protected $description = 'Kuyrukta işi olmayan "sırada/kuruluyor" sistem güncellemesi kayıtlarını düşürür';

    public function handle(OsUpdater $updater): int
    {
        if (Queue::size(config('os_update.queue')) > 0) {
            return self::SUCCESS;
        }

        $asili = OsUpdateState::query()
            ->whereIn('apply_status', [OsUpdateState::SIRADA, OsUpdateState::SURUYOR])
            ->where('updated_at', '<', now()->subMinutes(2))
            ->with('credential')
            ->get();

        foreach ($asili as $durum) {
            if ($durum->credential === null) {
                continue;
            }

            $updater->durum(
                $durum->credential,
                OsUpdateState::BASARISIZ,
                'Kurulum işi kuyrukta yok (sunucu ya da kuyruk yeniden başlatıldı); makinede sürmüş olabilir. Gerekirse yeniden başlatın.',
            );
        }

        if ($asili->isNotEmpty()) {
            $this->components->info($asili->count().' asılı kayıt düşürüldü.');
        }

        return self::SUCCESS;
    }
}
