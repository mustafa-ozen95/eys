<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Enums\ActivityAction;
use App\Jobs\CheckOsUpdatesJob;
use App\Jobs\RebootOsJob;
use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Rotation\RotationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Linux makineyi panelden yeniden başlatır.
 *
 * DAR BİR KAPI: yalnızca son denetimi "yeniden başlatma gerekli" diyen,
 * kilitsiz ve kurulumu sürmeyen makine. Yetki ayrıdır ('os_reboot');
 * gerekçe zorunludur ve işlem kaydına yazılır. Kurulum asla kendisi yeniden
 * başlatmaz; bu sınıf onun yerine geçmez, kişinin kararıdır.
 *
 * KOŞULLAR İKİ KEZ SORULUR: sıraya alırken (engel) ve bağlanmadan hemen
 * önce (calistir): kuyrukta beklerken kilitlenen makine yeniden başlatılmaz.
 */
class OsRebooter
{
    /** Aynı makineye üst üste ikinci yeniden başlatma gönderilmesin (sn). */
    private const ARALIK = 600;

    /** Makine kalkınca "yeniden başlatma gerekli" tazelensin diye denetim (sn sonra). */
    private const DENETIM_GECIKMESI = 300;

    public function __construct(
        private OsUpdateShell $shell,
        private OsUpdateTargets $targets,
        private ActivityRecorder $activity,
    ) {}

    /** Engel yoksa sıraya alır; varsa nedenini döndürür. */
    public function baslat(Credential $credential, User $actor, string $reason): ?string
    {
        $engel = $this->engel($credential);

        if ($engel !== null) {
            return $engel;
        }

        if (! Cache::add(self::anahtar($credential), true, self::ARALIK)) {
            return 'Bu makine az önce yeniden başlatıldı.';
        }

        RebootOsJob::dispatch($credential, $actor, $reason);

        return null;
    }

    public function calistir(Credential $credential, ?User $actor, ?string $reason): void
    {
        $engel = $this->engel($credential);

        if ($engel !== null) {
            $this->activity->record(ActivityAction::OsReboot, 'Yeniden başlatılmadı: '.$engel, $credential, $reason, $actor);

            return;
        }

        try {
            $this->shell->calistir($credential, OsUpdateScripts::yenidenBaslatma(), 60, 'Yeniden başlatılamadı.');
        } catch (RotationException $e) {
            Cache::forget(self::anahtar($credential));
            $this->activity->record(ActivityAction::OsReboot, 'Yeniden başlatılamadı: '.Str::limit($e->getMessage(), 150), $credential, $reason, $actor);

            return;
        }

        $this->activity->record(ActivityAction::OsReboot, 'Yeniden başlatma kuruldu (1 dakika sonra)', $credential, $reason, $actor);
        CheckOsUpdatesJob::dispatch($credential, elle: true)->delay(now()->addSeconds(self::DENETIM_GECIKMESI));
    }

    private function engel(Credential $credential): ?string
    {
        if (! $this->targets->hedefMi($credential)) {
            return 'SSH bilgisi tam, etkin bir Linux kaydı değil.';
        }

        $kilit = $this->targets->kilit($credential);

        if ($kilit !== null) {
            return 'Makine kilitli: '.$kilit->lock_reason;
        }

        $durumlar = OsUpdateState::query()
            ->whereIn('credential_id', $this->targets->kardesler($credential)->modelKeys())
            ->get();

        if ($durumlar->contains(fn (OsUpdateState $d): bool => $d->isBusy())) {
            return 'Bu makinede kurulum sürüyor.';
        }

        if (! $durumlar->contains('reboot_required', true)) {
            return 'Son denetim yeniden başlatma gerektirmiyor.';
        }

        return null;
    }

    private static function anahtar(Credential $credential): string
    {
        return 'os-reboot:'.OsUpdateTargets::makine($credential);
    }
}
