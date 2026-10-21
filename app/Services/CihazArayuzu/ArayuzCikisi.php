<?php

declare(strict_types=1);

namespace App\Services\CihazArayuzu;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cihazda açılmış oturumu SUNUCUDA kapatır.
 *
 * NEDEN VAR: oturumu biz açıyoruz (bkz. ArayuzGirisi), dolayısıyla kapatmak da
 * bize düşüyor. Kapatılmayan oturum cihazın kendi zaman aşımını bekliyor ve o
 * süre boyunca listede duruyor; art arda birkaç açılışta cihazın oturum
 * listesi panelin adresinden açılmış oturumlarla dolar.
 *
 * HATA YUTULUR ama KAYDA GEÇER: çıkış yapılamaması kullanıcının işini
 * bozmamalı — oturum zaten kapanıyor, cihazdaki artık yalnızca kendi zaman
 * aşımıyla düşer.
 */
final class ArayuzCikisi
{
    /** @return bool Cihazda oturum kapatıldı mı? */
    public function kapat(ArayuzTuru $tur, string $hedef, string $cerez): bool
    {
        if ($cerez === '' || ! $tur->sunucudaGiris()) {
            return false;
        }

        $taban = 'https://'.$hedef;

        try {
            return match ($tur) {
                ArayuzTuru::Plesk => $this->plesk($taban, $cerez),
                ArayuzTuru::Ilo => $this->ilo($taban, $cerez),
                ArayuzTuru::Vcenter => $this->vcenter($taban, $cerez),
                default => false,
            };
        } catch (Throwable $e) {
            Log::warning('Cihaz oturumu kapatılamadı.', [
                'tur' => $tur->value,
                'hedef' => $hedef,
                'hata' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Plesk: çıkış ucu.
     *
     * Plesk 303 dönüp giriş sayfasına yönlendiriyor. Yönlendirme İZLENMEZ — gereken tek şey oturumun düşmesi.
     */
    private function plesk(string $taban, string $cerez): bool
    {
        return $this->istek($cerez)->get($taban.'/logout.php')->status() < 400;
    }

    /**
     * iLO: giriş ucunun "logout" yöntemi.
     *
     * Oturum anahtarı ÇEREZİN İÇİNDEDİR (sessionKey=...) ve gövdede de
     * isteniyor; ikisi de aynı değer.
     *
     * iLO 4'te çağrıdan sonra açık oturum sayısı, yeni bir giriş dahil
     * öncekiyle aynı kalır — yani tam bir oturum düşer.
     */
    private function ilo(string $taban, string $cerez): bool
    {
        preg_match('/(?:^|;\s*)sessionKey=([^;]*)/', $cerez, $e);

        return $this->istek($cerez)->post($taban.'/json/login_session', [
            'method' => 'logout',
            'session_key' => $e[1] ?? '',
        ])->status() < 400;
    }

    /** vSphere Client: oturumu kendi çıkış ucu düşürüyor. */
    private function vcenter(string $taban, string $cerez): bool
    {
        return $this->istek($cerez)->get($taban.'/ui/logout')->status() < 400;
    }

    private function istek(string $cerez): PendingRequest
    {
        return Http::withOptions([
            'verify' => false,
            'allow_redirects' => false,
        ])
            ->withHeaders(['Cookie' => $cerez])
            /*
             * Kısa: çıkış bir temizlik işi ve kullanıcı onu beklemiyor.
             * Ulaşılamayan bir cihaz yüzünden kapanış kaydı gecikmemeli.
             */
            ->timeout(8);
    }
}
