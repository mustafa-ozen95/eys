<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Credential;
use App\Services\OsUpdate\OsRebooter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Yeniden başlatılan makinenin açılıp açılmadığına bakar (bkz.
 * OsRebooter::acilisiTakipEt). Kısa bir yoklama: açılış anını okur; açılmadıysa
 * kendini birkaç saniye sonraya yeniden kurar.
 *
 * Tekil DEĞİL: kendini yeniden kuyruğa koyuyor; tekil olsaydı kendi kilidine
 * takılıp düşerdi. Makine başına tek zincir RebootOsJob'un tekilliğinden gelir.
 */
class RebootFollowUpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        private Credential $credential,
        private ?int $eskiAcilis,
        private int $komut,
    ) {
        $this->onQueue(config('os_update.queue'));
    }

    public function handle(OsRebooter $rebooter): void
    {
        $rebooter->acilisiTakipEt($this->credential, $this->eskiAcilis, $this->komut);
    }
}
