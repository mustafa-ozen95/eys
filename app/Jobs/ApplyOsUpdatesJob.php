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
        /** Konsol yüzünden ilk bekletildiği an (unix); bekletilmediyse null. */
        private ?int $beklemeBasi = null,
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

        if ($this->konsolAcikken($updater, $targets)) {
            return;
        }

        $updater->kur($this->credential, $this->mode, $this->actor, $this->reason);
    }

    /**
     * KONSOL AÇIKKEN KURULUM BAŞLAMAZ.
     *
     * Makinenin başında biri varken (panelden açık konsol) kurulum o kişinin
     * işinin ortasına düşer: 19.11.2026'da NS1'in konsolu açıkken başlayan
     * kurulumdan 2 sn sonra makine vCenter'dan resetlendi; panel kopan
     * bağlantıyı 16 dk bekledi, sıradaki makineler de onu.
     *
     * Kurulum "Sırada" kalır, notu kimin konsolu olduğunu söyler; konsol
     * kapanınca kendiliğinden başlar. console_wait dolarsa atlanır.
     *
     * Yeniden kuyruğa koyan bir KAPANIŞ işi: bu iş tekil (ShouldBeUnique),
     * kilidi iş bitene dek tutuluyor; kendini buradan doğrudan kuyruğa koysa
     * kopya sayılıp düşerdi. Kapanış iş bittikten sonra çalışır.
     */
    private function konsolAcikken(OsUpdater $updater, OsUpdateTargets $targets): bool
    {
        $konsol = $targets->acikKonsol($this->credential);

        if ($konsol === null) {
            return false;
        }

        $kim = $konsol->user?->name ?? 'bir kullanıcı';
        $bas = $this->beklemeBasi ?? now()->timestamp;
        $sinir = (int) config('os_update.console_wait', 7200);

        if (now()->timestamp - $bas >= $sinir) {
            $updater->durum(
                $this->credential,
                OsUpdateState::ATLANDI,
                "Makinenin konsolu {$kim} tarafından ".intdiv($sinir, 60).' dakikadır açık; kurulum yapılmadı.',
            );

            return true;
        }

        $updater->beklet($this->credential, "Konsol açık ({$kim}); konsol kapanınca kurulacak.");

        $sonraki = new self($this->credential, $this->mode, $this->actor, $this->reason, $bas);
        dispatch(fn () => dispatch($sonraki))
            ->onQueue(config('os_update.queue'))
            ->delay(now()->addSeconds((int) config('os_update.console_retry', 60)));

        return true;
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
