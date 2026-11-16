<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Credential;
use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateTargets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Bekleyen sistem güncellemelerini okur. Makinede hiçbir şey kurmaz.
 *
 * Makine başına tek iş: aynı makinenin iki kaydı iki kez bağlanmasın.
 *
 * ELLE DENETİM SIRA BEKLEMEZ. Otomatik tur makineleri aralıklarla (gecikmeli)
 * sıraya koyuyor; tekillik anahtarı ortak olsaydı "Şimdi denetle" sessizce
 * düşer, makine otomatik sırasını (dakikalarca) beklerdi. Elle ve otomatik
 * işin anahtarları ayrı; aynı makineye aynı anda iki bağlantıyı ise
 * WithoutOverlapping önler. Sırası gelen otomatik iş, makine o arada
 * denetlenmişse bağlanmadan biter.
 */
class CheckOsUpdatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 900;

    private string $sirayaAlindi;

    public function __construct(private Credential $credential, private bool $elle = false)
    {
        $this->timeout = (int) config('os_update.check_timeout', 180) + 60;
        $this->sirayaAlindi = now()->toDateTimeString();
        $this->onQueue(config('os_update.queue'));
    }

    public function uniqueId(): string
    {
        return ($this->elle ? 'denetim-elle:' : 'denetim:').OsUpdateTargets::makine($this->credential);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        /* Aynı makinede süren denetim varsa ikincisi bağlanmaz; süren iş sonucu yazar. */
        return [(new WithoutOverlapping('os-update-denetim:'.OsUpdateTargets::makine($this->credential)))
            ->dontRelease()
            ->expireAfter($this->timeout + 60)];
    }

    public function handle(OsUpdater $updater, OsUpdateTargets $targets): void
    {
        /* Sıradayken kayıt hedef olmaktan çıkmış olabilir (pasif, silinmiş parola, başka platform). */
        if (! $targets->hedefMi($this->credential)) {
            return;
        }

        /* Otomatik iş beklerken makine elle denetlendiyse yeniden bağlanılmaz. */
        if (! $this->elle && $this->credential->osUpdateState()->where('checked_at', '>=', $this->sirayaAlindi)->exists()) {
            return;
        }

        $updater->denetle($this->credential);
    }
}
