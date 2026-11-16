<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Credential;
use App\Models\User;
use App\Services\OsUpdate\OsRebooter;
use App\Services\OsUpdate\OsUpdateTargets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makineyi yeniden başlatır (bkz. OsRebooter).
 *
 * tries = 1: yeniden başlatma kör tekrar edilmez; makine kalkarken ikinci
 * komut gitmesin.
 */
class RebootOsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        private Credential $credential,
        private ?User $actor = null,
        private ?string $reason = null,
    ) {
        $this->onQueue(config('os_update.queue'));
    }

    public function uniqueId(): string
    {
        return 'yeniden-baslat:'.OsUpdateTargets::makine($this->credential);
    }

    public function handle(OsRebooter $rebooter): void
    {
        $rebooter->calistir($this->credential, $this->actor, $this->reason);
    }
}
