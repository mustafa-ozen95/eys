<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Enums\ActivityAction;
use App\Jobs\CheckOsUpdatesJob;
use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Rotation\RotationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Denetim ve kurulumun kendisi. Kuyruk işleri yalnızca bunu çağırır.
 *
 * KİLİT İKİ KEZ SORULUR: sıraya alırken (OsUpdateLauncher) ve makineye
 * bağlanmadan hemen önce (burada). Kuyrukta beklerken kilitlenen makineye
 * de dokunulmaz.
 */
class OsUpdater
{
    /** Kurulumdan sonra yeniden denetim: hemen, gecikmeli ya da hiç. */
    private const HEMEN = 'hemen';

    private const SONRA = 'sonra';

    private const YOK = 'yok';

    public function __construct(
        private OsUpdateShell $shell,
        private OsUpdateTargets $targets,
        private ActivityRecorder $activity,
    ) {}

    /** Bekleyen güncellemeleri okur; hiçbir şey kurmaz. Sonuç makinenin bütün kayıtlarına yazılır. */
    public function denetle(Credential $credential): void
    {
        try {
            $cikti = $this->shell->calistir(
                $credential,
                OsUpdateScripts::denetim(),
                (int) config('os_update.check_timeout', 180),
                'Denetim yapılamadı.',
            );
        } catch (RotationException $e) {
            $this->yaz($credential, ['checked_at' => now(), 'check_error' => Str::limit($e->getMessage(), 290)]);

            return;
        }

        $rapor = PackageReport::parse($cikti);

        $this->yaz($credential, [
            'package_manager' => $rapor->manager,
            'os_name' => $rapor->os,
            'critical_count' => $rapor->critical,
            'normal_count' => $rapor->normal,
            'packages' => $rapor->packages,
            'reboot_required' => $rapor->rebootRequired,
            'checked_at' => now(),
            'check_error' => match (true) {
                $rapor->manager === null => 'Desteklenen paket yöneticisi (apt, dnf, yum) bulunamadı.',
                $rapor->repoWarning => 'Depo listesi yenilenemedi; sayılar eski listeye göre.',
                default => null,
            },
        ]);
    }

    public function kur(Credential $credential, string $mod, ?User $actor, ?string $reason): void
    {
        $kilit = $this->targets->kilit($credential);

        if ($kilit !== null) {
            $this->durum($credential, OsUpdateState::ATLANDI, 'Makine kilitli: '.$kilit->lock_reason);

            return;
        }

        /* Aynı makinede iki kurulum yarışmasın (iki kayıt, iki kişi). */
        $makineKilidi = Cache::lock(
            'os-update:'.OsUpdateTargets::makine($credential),
            (int) config('os_update.apply_timeout', 840) + 60,
        );

        if (! $makineKilidi->get()) {
            $this->durum($credential, OsUpdateState::ATLANDI, 'Bu makinede başka bir kurulum sürüyor.');

            return;
        }

        try {
            $this->durum($credential, OsUpdateState::SURUYOR);
            $etiket = $mod === OsUpdateScripts::KRITIK ? 'yalnızca kritik' : 'tümü';

            /*
             * Kurulumdan sonra bekleyen sayılar tazelenir — BAŞARISIZ olsa da:
             *  - hatayla bitti (kod var): paketlerin bir kısmı kurulmuş olabilir, hemen denetlenir;
             *  - sonuç okunamadı (süre doldu): kurulum makinede oturumdan bağımsız sürüyor
             *    olabilir (bkz. OsUpdateScripts::kurulum); şimdi denetlemek apt/dnf kilidine
             *    takılır, recheck_after saniye sonra denetlenir;
             *  - bağlantı/kimlik hatası: makineye ulaşılamıyor, denetim de ulaşamaz.
             */
            $yeniden = self::HEMEN;

            try {
                $cikti = $this->shell->calistir(
                    $credential,
                    OsUpdateScripts::kurulum($mod),
                    (int) config('os_update.apply_timeout', 840),
                    'Güncelleme kurulamadı; ayrıntı makinede '.OsUpdateScripts::GUNLUK.'.',
                );
                [$kod, $satirlar] = OsUpdateScripts::kurulumSonucu($cikti);
                $hata = self::kurulumHatasi($kod, $satirlar);

                if ($kod === null) {
                    $yeniden = self::SONRA;
                    $hata .= ' Makinede sürüyor olabilir; '.intdiv((int) config('os_update.recheck_after', 900), 60).' dk sonra yeniden denetlenir.';
                }
            } catch (RotationException $e) {
                $hata = $e->getMessage();
                $yeniden = self::YOK;
            }

            if ($hata !== null) {
                $this->durum($credential, OsUpdateState::BASARISIZ, $hata);
                $this->activity->record(ActivityAction::OsUpdate, "Sistem güncellemesi kurulamadı ({$etiket}): ".Str::limit($hata, 150), $credential, $reason, $actor);
            } else {
                $this->durum($credential, OsUpdateState::BASARILI);
                $this->activity->record(ActivityAction::OsUpdate, "Sistem güncellemesi kuruldu ({$etiket})", $credential, $reason, $actor);
            }
        } finally {
            $makineKilidi->release();
        }

        /* Sayılar ve "yeniden başlatma gerekli" tazelenir. */
        match ($yeniden) {
            self::HEMEN => $this->denetle($credential),
            self::SONRA => CheckOsUpdatesJob::dispatch($credential, elle: true)
                ->delay(now()->addSeconds((int) config('os_update.recheck_after', 900))),
            self::YOK => null,
        };
    }

    /**
     * Kurulumun sonucu: null başarılı demek. Başarısızsa günlüğün SON
     * satırları iletiye girer — sığmazsa baştakiler atılır, hatanın kendisi
     * genellikle sondadır.
     *
     * @param  list<string>  $satirlar
     */
    public static function kurulumHatasi(?int $kod, array $satirlar): ?string
    {
        if ($kod === 0) {
            return null;
        }

        $bas = $kod === null
            ? 'Kurulumun sonucu okunamadı; ayrıntı makinede '.OsUpdateScripts::GUNLUK.'.'
            : "Kurulum hatayla bitti (kod {$kod}).";

        while ($satirlar !== [] && mb_strlen($bas.' '.implode(' · ', $satirlar)) > 285) {
            array_shift($satirlar);
        }

        return $satirlar === [] ? $bas : $bas.' '.implode(' · ', $satirlar);
    }

    /** Kilidi koyar ya da kaldırır; kim, ne zaman, neden işlem kaydına yazılır. */
    public function kilitle(Credential $credential, bool $kilitli, User $actor, string $reason): void
    {
        DB::transaction(function () use ($credential, $kilitli, $actor, $reason): void {
            OsUpdateState::query()->updateOrCreate(['credential_id' => $credential->getKey()], [
                'locked' => $kilitli,
                'lock_reason' => $kilitli ? $reason : null,
                'locked_by' => $kilitli ? $actor->getKey() : null,
                'locked_at' => $kilitli ? now() : null,
            ]);

            $this->activity->record(
                ActivityAction::OsUpdateLock,
                $kilitli ? 'Güncelleme kilidi kondu: bu makineye güncelleme kurulmaz' : 'Güncelleme kilidi kaldırıldı',
                $credential,
                $reason,
                $actor,
            );
        });
    }

    /** Kurulum durumunu yazar. */
    public function durum(Credential $credential, string $durum, ?string $hata = null, ?string $mod = null, ?User $actor = null): void
    {
        $alanlar = ['apply_status' => $durum, 'apply_error' => $hata === null ? null : Str::limit($hata, 290)];

        if ($durum === OsUpdateState::SIRADA) {
            $alanlar += ['apply_queued_at' => now(), 'apply_finished_at' => null, 'apply_mode' => $mod, 'applied_by' => $actor?->getKey()];
        }

        if (in_array($durum, [OsUpdateState::BASARILI, OsUpdateState::BASARISIZ, OsUpdateState::ATLANDI], true)) {
            $alanlar['apply_finished_at'] = now();
        }

        OsUpdateState::query()->updateOrCreate(['credential_id' => $credential->getKey()], $alanlar);
    }

    /**
     * Denetim sonucunu makinenin BÜTÜN kayıtlarına yazar.
     *
     * @param  array<string, mixed>  $alanlar
     */
    private function yaz(Credential $credential, array $alanlar): void
    {
        $kayitlar = $this->targets->kardesler($credential);

        if (! $kayitlar->contains($credential)) {
            $kayitlar->push($credential);
        }

        foreach ($kayitlar as $kayit) {
            OsUpdateState::query()->updateOrCreate(['credential_id' => $kayit->getKey()], $alanlar);
        }
    }
}
