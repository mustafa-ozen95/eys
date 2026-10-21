<?php

declare(strict_types=1);

namespace App\Services\CihazArayuzu;

use App\Enums\ActivityAction;
use App\Models\Credential;
use App\Models\KonsolOturumu;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\Cache;

/**
 * Vekilden geçen cihaz çağrılarını kayda geçirir.
 *
 * Arayüzün BÜTÜN yönetim çağrıları panelin vekilinden geçiyor; gövdedeki SOAP
 * yöntem adı "ne yapıldı" sorusunun cevabıdır. "Kim girdi" kadar "ne yaptı"
 * da denetimin sorduğu sorudur ve vekil olmasaydı bu bilgi hiç elimizde
 * olmazdı.
 *
 * GÜRÜLTÜ KAYDA GİRMEZ. Arayüz saniyede birkaç kez okuma çağrısı atıyor
 * (özellik toplayıcı, uzun yoklama, saat sorgusu); hepsini yazmak denetim
 * kaydını kullanılmaz hâle getirirdi. Yazılan şey DEĞİŞTİREN çağrılardır.
 */
final class ArayuzIzleyici
{
    public function __construct(private ActivityRecorder $activity) {}

    /**
     * Adında OKUMA fiili geçen çağrılar yazılmaz.
     *
     * Süzgeç "neyi atlayacağız" diye kuruluyor, "neyi yazacağız" diye değil:
     * VMware her sürümde yeni yöntem ekliyor ve beyaz liste, yeni bir yönetim
     * çağrısını SESSİZCE kayda geçirmemek demek olurdu. Bilinmeyen bir ad
     * kayda GİRER.
     *
     * vim25'te okuma yöntemlerinin adında bu fiillerden biri geçiyor
     * (QueryDateTime, HostImageConfigGetProfile, RetrieveProperties...).
     * Değiştiren çağrılar ise ya bir eylem fiiliyle başlıyor
     * (PowerOnVM, ReconfigVM, Destroy, EnterMaintenanceMode) ya da "_Task"
     * ile bitiyor.
     */
    private const OKUMA_FIILLERI = [
        'Query', 'Retrieve', 'Fetch', 'Get', 'Read', 'Check', 'Find',
        'List', 'Has', 'Validate', 'Lookup', 'Browse',
    ];

    /**
     * Okuma fiili taşımadığı hâlde iş sayılmayacak çağrılar.
     *
     * Yoklama ve görünüm yönetimi arayüzün kendi iç işleyişi; kullanıcının
     * yaptığı bir şey değil. Hepsini yazmak kaydı kullanılmaz hâle getirirdi:
     * tek bir açılışta onlarca satır.
     */
    private const GURULTU = [
        'CreateFilter', 'DestroyPropertyFilter', 'WaitForUpdatesEx', 'CancelWaitForUpdates',
        'CreateContainerView', 'DestroyView', 'CurrentTime', 'SetLocale',
        'ContinueRetrievePropertiesEx', 'CancelRetrievePropertiesEx', 'CancelTask',
    ];

    /** Oturum kapanışını anlatan çağrı. */
    private const CIKIS = 'Logout';

    /** Girişi anlatan çağrı. */
    private const GIRIS = 'Login';

    /**
     * Gövdeden SOAP yöntem adını çıkarır.
     *
     * Zarf ayrıştırılmaz: gövde büyük olabiliyor ve burada tek bir ad
     * aranıyor. Gövdenin ilk elemanı yöntemdir.
     */
    public function yontem(string $govde): ?string
    {
        if (preg_match('#<(?:\w+:)?Body>\s*<(?:\w+:)?([A-Za-z_][\w.-]*)#', $govde, $eslesme) !== 1) {
            return null;
        }

        return $eslesme[1];
    }

    /**
     * Çağrıyı kayda geçirir (gerekiyorsa).
     *
     * Tür başına AYRI: ESXi SOAP konuşuyor ve "ne yapıldı" gövdedeki yöntem
     * adında; VAMI REST konuşuyor ve aynı bilgi yöntem+yolda.
     *
     * @param  array<string, mixed>  $oturum
     */
    public function kaydet(array $oturum, string $govde, ?string $yol = null, ?string $istekYontemi = null): void
    {
        if (ArayuzTuru::tryFrom((string) ($oturum['tur'] ?? '')) === ArayuzTuru::Vami) {
            $this->vamiyiKaydet($oturum, (string) $yol, (string) $istekYontemi);

            return;
        }

        $yontem = $this->yontem($govde);

        if ($yontem === null) {
            return;
        }

        $credential = Credential::query()->find($oturum['credential_id']);
        $user = User::query()->find($oturum['user_id']);

        if ($yontem === self::GIRIS) {
            $this->girisiYaz($oturum, $credential, $user);

            return;
        }

        if ($yontem === self::CIKIS) {
            $this->cikisiYaz($oturum, $credential, $user);

            return;
        }

        if ($this->gurultuMu($yontem)) {
            return;
        }

        /*
         * Aynı çağrı arka arkaya birkaç kez gelebiliyor (arayüz yeniden
         * deniyor). Kısa bir pencerede tekrarı yazmak kaydı şişirirdi.
         */
        $anahtar = 'eys:arayuz-son:'.$oturum['token'].':'.$yontem;

        if (Cache::has($anahtar)) {
            return;
        }

        Cache::put($anahtar, true, 5);

        $this->activity->record(
            ActivityAction::DeviceOperation,
            "{$oturum['label']} · ESXi arayüzünde işlem: {$yontem}",
            $credential,
            actor: $user,
        );
    }

    /**
     * VAMI: REST çağrısı.
     *
     * OKUMA yazılmaz (GET), DEĞİŞTİREN yazılır. Oturum ucu girişin/çıkışın
     * kendisidir; gerisi yolundan okunuyor — "appliance/shutdown/reboot"
     * cümlenin tamamını zaten söylüyor.
     *
     * @param  array<string, mixed>  $oturum
     */
    private function vamiyiKaydet(array $oturum, string $yol, string $istekYontemi): void
    {
        $sorgu = (string) parse_url($yol, PHP_URL_QUERY);
        $yol = (string) parse_url($yol, PHP_URL_PATH);
        $kisa = ltrim(preg_replace('#^/rest/#', '', $yol) ?? $yol, '/');

        $credential = Credential::query()->find($oturum['credential_id']);
        $user = User::query()->find($oturum['user_id']);

        if (str_contains($yol, 'cis/session')) {
            /* Silme eylemi çıkıştır; ötekisi giriş. */
            if (str_contains($sorgu.$yol, 'delete')) {
                $this->cikisiYaz($oturum, $credential, $user);
            } else {
                $this->girisiYaz($oturum, $credential, $user);
            }

            return;
        }

        if (mb_strtoupper($istekYontemi) === 'GET' || $kisa === '') {
            return;
        }

        $anahtar = 'eys:arayuz-son:'.$oturum['token'].':'.$istekYontemi.':'.$kisa;

        if (Cache::has($anahtar)) {
            return;
        }

        Cache::put($anahtar, true, 5);

        $this->activity->record(
            ActivityAction::DeviceOperation,
            "{$oturum['label']} · Appliance arayüzünde işlem: ".mb_strtoupper($istekYontemi)." {$kisa}",
            $credential,
            actor: $user,
        );
    }

    /**
     * Bu çağrı kayda değer mi?
     *
     * "_Task" ile biten çağrı HER ZAMAN yazılır: uzun süren iş demektir ve
     * adında okuma fiili geçse bile cihazda bir şey değiştiriyordur.
     */
    private function gurultuMu(string $yontem): bool
    {
        if (str_ends_with($yontem, '_Task')) {
            return false;
        }

        if (in_array($yontem, self::GURULTU, true)) {
            return true;
        }

        /* Küçük harfle başlayan ad bir özellik okumasıdır (installDate gibi). */
        if ($yontem !== '' && mb_strtolower($yontem[0]) === $yontem[0]) {
            return true;
        }

        foreach (self::OKUMA_FIILLERI as $fiil) {
            if (str_contains($yontem, $fiil)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $oturum */
    private function etiket(array $oturum): string
    {
        return ArayuzTuru::tryFrom((string) ($oturum['tur'] ?? ''))?->etiket() ?? 'Cihaz arayüzü';
    }

    /** @param  array<string, mixed>  $oturum */
    private function girisiYaz(array $oturum, ?Credential $credential, ?User $user): void
    {
        $anahtar = 'eys:arayuz-giris:'.$oturum['token'];

        /* Giriş bir kez yazılır: arayüz başarısızlıkta yeniden deniyor. */
        if (Cache::has($anahtar)) {
            return;
        }

        Cache::put($anahtar, true, (int) config('remote.session.ttl'));

        KonsolOturumu::query()
            ->where('token', $oturum['token'])
            ->update(['last_seen_at' => now()]);

        $this->activity->record(
            ActivityAction::ConsoleOpened,
            "{$oturum['label']} · ".$this->etiket($oturum).'ne giriş yapıldı',
            $credential,
            actor: $user,
        );
    }

    /** @param  array<string, mixed>  $oturum */
    private function cikisiYaz(array $oturum, ?Credential $credential, ?User $user): void
    {
        $satir = KonsolOturumu::query()->where('token', $oturum['token'])->whereNull('closed_at')->first();

        if ($satir === null) {
            return;
        }

        $satir->update(['closed_at' => now(), 'closed_reason' => 'cikis']);

        $this->activity->record(
            ActivityAction::ConsoleClosed,
            "{$oturum['label']} · ".$this->etiket($oturum).'nden çıkış yapıldı',
            $credential,
            actor: $user,
        );
    }
}
