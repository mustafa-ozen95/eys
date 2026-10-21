<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\Credential;
use App\Services\DeviceAddress;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * vCenter'ın kimlik sağlayıcı (websso) adresini alır.
 *
 * vSphere Client giriş almıyor: `/ui/login` tarayıcıyı 302 ile vCenter'ın
 * kendi kimlik sağlayıcısına yolluyor ve adresin içinde İMZALI bir
 * SAMLRequest taşıyor. Giriş o adrese gönderilir.
 *
 * Adres KAYITTA TUTULMAZ, her fırlatmada yeniden alınır: içindeki istek
 * imzalıdır, kısa ömürlüdür ve vCenter yeniden başlatıldığında değişir.
 * Saklanmış bir adres, sessizce çalışmayan bir düğme demek olurdu.
 *
 * vCenter 8'de adres SUNUCU tarafında alınabiliyor. Kimlik sağlayıcı isteği imzasından doğruluyor, tarayıcının o
 * ana kadar hiç çerezi olmasını beklemiyor; SAML yanıtını alan vCenter de
 * taze bir tarayıcıyı kabul edip oturum çerezini kendisi yazıyor.
 */
final class VcenterSsoAddress
{
    /** Yönlendirme başka bir makineye gitmemeli: kimlik oraya gönderilecek. */
    public static function al(Credential $credential): ?string
    {
        $taban = DeviceAddress::for($credential);

        try {
            $yanit = Http::withOptions([
                /*
                 * vCenter sertifikası kendi otoritesinden; kurulumların
                 * neredeyse tamamında imzasız. Doğrulama kapalı olduğu için
                 * BURADAN PAROLA GEÇMİYOR: yalnızca yönlendirme adresi
                 * okunuyor. Parolayı gönderen taraf kullanıcının tarayıcısı
                 * ve o, cihazın sertifikasını kendi doğruluyor.
                 */
                'verify' => false,
                'allow_redirects' => false,
            ])
                ->timeout(15)
                ->get($taban.'/ui/login');
        } catch (Throwable) {
            return null;
        }

        $adres = $yanit->header('Location');

        if ($adres === '') {
            return null;
        }

        return self::ayniMakine($adres, $taban) ? $adres : null;
    }

    /**
     * Yönlendirme AYNI makineye mi?
     *
     * Adres yanıttan geliyor; kimlik oraya gönderilecek. Başka bir konağa
     * yönlendiren bir yanıt, parolayı üçüncü bir yere taşırdı — cihaz ele
     * geçirilmişse tek adımda kasa boşalırdı.
     */
    private static function ayniMakine(string $adres, string $taban): bool
    {
        $a = parse_url($adres);
        $b = parse_url($taban);

        return ($a['scheme'] ?? '') === 'https'
            && isset($a['host'], $b['host'])
            && strcasecmp($a['host'], $b['host']) === 0
            && ($a['port'] ?? 443) === ($b['port'] ?? 443);
    }
}
