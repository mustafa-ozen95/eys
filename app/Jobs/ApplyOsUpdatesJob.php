<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateTargets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makineye sistem güncellemesi kurar.
 *
 * tries = 1: paket kurulumu kör tekrar edilmez; yarıda kalan bir kurulumun
 * üstüne ikinci kurulum başlatmak dpkg kilidiyle çarpışır. Tekrar, kişinin
 * yeniden başlatmasıyla olur.
 */
class ApplyOsUpdatesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(
        private Credential $credential,
        private string $mode,
        private ?User $actor = null,
        private ?string $reason = null,
    ) {
        $this->timeout = (int) config('os_update.apply_timeout', 840) + 60;
        $this->uniqueFor = $this->timeout + 60;
        $this->onQueue(config('os_update.queue'));
    }

    public function uniqueId(): string
    {
        return 'kurulum:'.OsUpdateTargets::makine($this->credential);
    }

    public function handle(OsUpdater $updater, OsUpdateTargets $targets): void
    {
        if (! $targets->hedefMi($this->credential)) {
            $updater->durum($this->credential, OsUpdateState::ATLANDI, 'Kayıt artık güncellenebilir bir Linux kaydı değil.');

            return;
        }

        $updater->kur($this->credential, $this->mode, $this->actor, $this->reason);
    }

    /** İşçi öldüyse ya da süre dolduysa durum "sürüyor"da asılı kalmasın. */
    public function failed(?Throwable $e): void
    {
        app(OsUpdater::class)->durum(
            $this->credential,
            OsUpdateState::BASARISIZ,
            'Kurulum işi yarıda kesildi; makinede sürüyor olabilir, ayrıntı makinede /var/log/eys-sistem-guncelleme.log.',
        );
    }
}
