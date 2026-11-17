<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckOsUpdatesJob;
use App\Models\Credential;
use App\Services\OsUpdate\OsUpdateTargets;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Son denetimi aralıktan eski Linux makineleri denetim için sıraya alır.
 * YALNIZCA OKUR; hiçbir makineye paket kurmaz.
 *
 *     php artisan os-update:check            zamanı gelenler
 *     php artisan os-update:check --hepsi    hepsi, zamanına bakmadan
 *
 * Aralık Ayarlar → Sistem güncellemeleri'nden gelir (os_update.check_hours);
 * 0 ise --hepsi verilmedikçe hiçbir makineye bağlanılmaz.
 */
class OsUpdateCheckCommand extends Command
{
    protected $signature = 'os-update:check {--hepsi : Zamanına bakmadan bütün makineler}';

    protected $description = 'Linux makinelerde bekleyen sistem güncellemelerini denetler (yalnızca okur)';

    public function handle(OsUpdateTargets $targets): int
    {
        $saat = (int) config('os_update.check_hours', 4);
        $hepsi = (bool) $this->option('hepsi');

        if ($saat <= 0 && ! $hepsi) {
            $this->components->info('Otomatik denetim kapalı.');

            return self::SUCCESS;
        }

        /* Saatlik turun kaymasıyla 4 saat 5 saate dönmesin: beş dakika pay. */
        $sinir = now()->subHours(max($saat, 1))->addMinutes(5);
        $aralik = (int) config('os_update.stagger_seconds', 20);

        /* Hariç kayıtla bağlanılmaz: makine başına seçilen kayıt hariç olmayanlardan. */
        $kayitlar = $targets->sorgu()
            ->whereDoesntHave('osUpdateState', fn (Builder $d) => $d->where('excluded', true))
            ->when(! $hepsi, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereDoesntHave('osUpdateState')
                ->orWhereHas('osUpdateState', fn (Builder $d) => $d
                    ->whereNull('checked_at')->orWhere('checked_at', '<=', $sinir))))
            ->orderBy('id')
            ->get()
            ->unique(fn (Credential $kayit): string => OsUpdateTargets::makine($kayit))
            ->values();

        foreach ($kayitlar as $sira => $kayit) {
            CheckOsUpdatesJob::dispatch($kayit)->delay(now()->addSeconds($sira * $aralik));
        }

        $this->components->info($kayitlar->count().' makine denetim için sıraya alındı.');

        return self::SUCCESS;
    }
}
