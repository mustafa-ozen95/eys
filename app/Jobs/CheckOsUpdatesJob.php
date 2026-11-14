<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Credential;
use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateTargets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Bekleyen sistem güncellemelerini okur. Makinede hiçbir şey kurmaz.
 *
 * Makine başına tek iş: aynı makinenin iki kaydı iki kez bağlanmasın.
 */
class CheckOsUpdatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 900;

    public function __construct(private Credential $credential)
    {
        $this->timeout = (int) config('os_update.check_timeout', 180) + 60;
        $this->onQueue(config('os_update.queue'));
    }

    public function uniqueId(): string
    {
        return 'denetim:'.OsUpdateTargets::makine($this->credential);
    }

    public function handle(OsUpdater $updater, OsUpdateTargets $targets): void
    {
        /* Sıradayken kayıt hedef olmaktan çıkmış olabilir (pasif, silinmiş parola, başka platform). */
        if (! $targets->hedefMi($this->credential)) {
            return;
        }

        $updater->denetle($this->credential);
    }
}
