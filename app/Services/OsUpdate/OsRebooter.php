<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Enums\ActivityAction;
use App\Jobs\CheckOsUpdatesJob;
use App\Jobs\RebootFollowUpJob;
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

    /*
     * AÇILIŞ TAKİBİ (bkz. acilisiTakipEt).
     *
     * Eskiden makine 5 dk sonra körlemesine denetleniyordu: bu arada
     * "Yeniden başlatma gerekli" ekranda kalıyor, ilerleme görünmüyor, o
     * sırada elle istenen denetim de bekleyen işle aynı tekil anahtara
     * düşüp sessizce atılıyordu (19.11.2026, KAPI_DB ve MailGateway: kart
     * 0/2 "sırada" kaldı). Şimdi komuttan ILK sn sonra başlayıp YOKLAMA sn
     * arayla makinenin açılış anına bakılır; açılış anı değişince makine
     * yeniden başlamış demektir, hemen denetlenir. PENCERE dolarsa
     * "açılmadı" yazılır.
     */
    private const SONUC = 'os-reboot-sonuc:';

    private const ILK = 75;

    private const YOKLAMA = 20;

    private const PENCERE = 600;

    /** Tur göstergesi için makine başına son yeniden başlatmanın durumu (bkz. OsUpdateRound). */
    public const SIRADA = 'sirada';

    public const SURUYOR = 'suruyor';

    public const BASARILI = 'basarili';

    public const BASARISIZ = 'basarisiz';

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

        self::isaretle($credential, self::SIRADA);
        RebootOsJob::dispatch($credential, $actor, $reason);

        return null;
    }

    public function calistir(Credential $credential, ?User $actor, ?string $reason): void
    {
        $engel = $this->engel($credential);

        if ($engel !== null) {
            self::isaretle($credential, self::BASARISIZ, $engel);
            $this->activity->record(ActivityAction::OsReboot, 'Yeniden başlatılmadı: '.$engel, $credential, $reason, $actor);

            return;
        }

        try {
            $cikti = $this->shell->calistir($credential, OsUpdateScripts::yenidenBaslatma(), 60, 'Yeniden başlatılamadı.');
        } catch (RotationException $e) {
            /*
             * Komut gidemedi: denetim BEKLEMEDEN yapılır, satır eski uyarıyla
             * sessizce kalmasın, bağlantı hatası ekranda görünsün
             * (17.11.2026: test sunucuları kapalıydı, "No route to host").
             */
            Cache::forget(self::anahtar($credential));
            self::isaretle($credential, self::BASARISIZ, $e->getMessage());
            $this->activity->record(ActivityAction::OsReboot, 'Yeniden başlatılamadı: '.Str::limit($e->getMessage(), 150), $credential, $reason, $actor);
            CheckOsUpdatesJob::dispatch($credential, elle: true);

            return;
        }

        $this->activity->record(ActivityAction::OsReboot, 'Yeniden başlatma kuruldu (1 dakika sonra)', $credential, $reason, $actor);
        self::isaretle($credential, self::SURUYOR);
        RebootFollowUpJob::dispatch($credential, OsUpdateScripts::acilisOku($cikti), now()->timestamp)
            ->delay(now()->addSeconds(self::ILK));
    }

    /**
     * Makine yeniden açıldı mı? Açılış anı komuttan önceki açılış anından
     * SONRAYSA evet: "yeniden başlatma gerekli" kalkar, makine hemen
     * denetlenir. Bağlanılamıyorsa ya da açılış anı aynıysa makine henüz
     * kapanmamış ya da açılıyordur; YOKLAMA sn sonra yeniden bakılır.
     *
     * Eski açılış anı okunamadıysa (beklenmedik çıktı) komut anı kıyas
     * alınır; ikisi farklı saatlerden geldiği için 2 dk pay bırakılır.
     */
    public function acilisiTakipEt(Credential $credential, ?int $eskiAcilis, int $komut): void
    {
        try {
            $yeniAcilis = OsUpdateScripts::acilisOku(
                $this->shell->calistir($credential, OsUpdateScripts::acilisAni(), self::YOKLAMA, 'Makineye bağlanılamadı.'),
            );
        } catch (RotationException) {
            $yeniAcilis = null;
        }

        $sinir = $eskiAcilis ?? $komut - 120;

        if ($yeniAcilis !== null && $yeniAcilis > $sinir + 5) {
            self::isaretle($credential, self::BASARILI);
            OsUpdateState::query()
                ->whereIn('credential_id', $this->targets->kardesler($credential)->modelKeys())
                ->update(['reboot_required' => false]);
            CheckOsUpdatesJob::dispatch($credential, elle: true);

            return;
        }

        if (now()->timestamp - $komut >= self::PENCERE) {
            $neden = $yeniAcilis === null
                ? 'Makine '.intdiv(self::PENCERE, 60).' dakikada yeniden açılmadı.'
                : 'Makine yeniden başlamadı (açılış anı değişmedi).';
            self::isaretle($credential, self::BASARISIZ, $neden);
            $this->activity->record(ActivityAction::OsReboot, $neden, $credential);
            CheckOsUpdatesJob::dispatch($credential, elle: true);

            return;
        }

        RebootFollowUpJob::dispatch($credential, $eskiAcilis, $komut)->delay(now()->addSeconds(self::YOKLAMA));
    }

    /** Yeniden başlatma sıraya alındı ya da makine açılması bekleniyor. */
    public static function suruyorMu(Credential $credential): bool
    {
        $sonuc = self::sonuc($credential->getKey());

        return $sonuc !== null
            && in_array($sonuc['durum'], [self::SIRADA, self::SURUYOR], true)
            && now()->timestamp - $sonuc['zaman'] < self::PENCERE + 60;
    }

    /** @return array{durum: string, zaman: int, hata: string|null}|null */
    public static function sonuc(int $kimlik): ?array
    {
        $veri = Cache::get(self::SONUC.$kimlik);

        return is_array($veri) ? $veri : null;
    }

    private static function isaretle(Credential $credential, string $durum, ?string $hata = null): void
    {
        Cache::put(self::SONUC.$credential->getKey(), ['durum' => $durum, 'zaman' => now()->timestamp, 'hata' => $hata], 21600);
    }

    private function engel(Credential $credential): ?string
    {
        if (! $this->targets->hedefMi($credential)) {
            return 'SSH bilgisi tam, etkin bir Linux kaydı değil.';
        }

        if ($this->targets->panelSunucusu($credential)) {
            return OsUpdater::PANEL_SUNUCUSU.'.';
        }

        if ($this->targets->haricMi($credential)) {
            return OsUpdater::HARIC.'.';
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
