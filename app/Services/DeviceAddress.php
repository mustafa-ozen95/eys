<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Credential;

/**
 * Bir kaydın web adresi.
 *
 * ŞEMA TAHMİN EDİLMEZ. Cihazların hepsi https konuşmuyor ve ikisi arasındaki
 * fark sessiz değil: bir cihaz düz http'yi 303 ile https'e yolluyor, özel
 * bir porttan yayın yapan bir başkası ise http isteğine hiç yanıt vermiyor.
 * Yanlış şema, "bağlanamadı" diye görünen ama aslında yanlış kapıyı çalan bir
 * hata üretirdi.
 *
 * Kural: kaydın URL alanı doluysa OLDUĞU GİBİ kullanılır — şemasını kullanıcı
 * seçmiştir. Boşsa https varsayılır; kimlik taşıyan bir isteği kendiliğimizden
 * düz http'ye düşürmek parolayı ağa açıkta yazmak olurdu.
 *
 * Bu hesap sekiz ayrı yerde kopyalanmıştı (sürücüler, portal, liste); tek
 * kopya olmadığı sürece "port alanı burada da geçerli mi" sorusunun yanıtı
 * dosyadan dosyaya değişiyordu.
 */
final class DeviceAddress
{
    /**
     * @param  int  $assumedPort  Kayıtta port yazmıyorsa bu platformun olağan
     *                            portu (Plesk 8443, iLO/ESXi 443).
     */
    public static function for(Credential $credential, int $assumedPort = 443): string
    {
        if (filled($credential->url)) {
            return self::normalize((string) $credential->url);
        }

        $port = $credential->port ?? $assumedPort;

        return 'https://'.$credential->host.($port === 443 ? '' : ':'.$port);
    }

    /**
     * Aynı makine, BAŞKA KAPI.
     *
     * Kaydın portu kullanıcının gittiği arayüzü gösterir; sürücü ise başka
     * bir kapıdan konuşabilir. Plesk paneli 8443'te ama parola SSH'tan
     * (22) değişir; vCenter yönetim arayüzü 5480'de ama REST API 443'tedir
     * (5480/api/session 411 döndürüyor, 443 ise 201).
     * Kaydın portunu "doğru" diye bozmak, kullanıcının bağlantısını
     * kırardı; ayrım burada yapılır.
     */
    public static function port(Credential $credential, int $port): string
    {
        $parca = parse_url(self::for($credential));
        $sema = $parca['scheme'] ?? 'https';
        $varsayilan = $sema === 'http' ? 80 : 443;

        return $sema.'://'.($parca['host'] ?? (string) $credential->host)
            .($port === $varsayilan ? '' : ':'.$port);
    }

    /** Şemasız yazılmış adres ("192.0.2.9:8443") de çalışsın. */
    private static function normalize(string $url): string
    {
        $url = trim($url);

        if (preg_match('~^https?://~i', $url) !== 1) {
            $url = 'https://'.ltrim($url, '/');
        }

        return rtrim($url, '/');
    }
}
