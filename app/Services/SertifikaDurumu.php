<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * TLS sertifikasının kalan süresi.
 *
 * Değer, sunucudaki sertifika bekçisinin haftalık yazdığı dosyadan okunur. Panel sertifikayı KENDİSİ sorgulasaydı her istekte
 * dışarı bir TLS el sıkışması gerekirdi; dosyayı okumak bedava.
 *
 * Dosya yoksa (bağlanmamış kurulum, eski sürüm) hiçbir uyarı basılmaz —
 * eksik veri yüzünden ekranda yanlış alarm çıkmasın.
 */
class SertifikaDurumu
{
    /** Bu günden azı kaldıysa uyarı basılır. */
    public const ESIK = 21;

    private const ONBELLEK = 'sertifika-durumu';

    /** @return array{veren: string, bitis: string, kalan_gun: int, ic_ca: bool}|null */
    public static function oku(): ?array
    {
        return Cache::remember(self::ONBELLEK, now()->addMinutes(30), static function (): ?array {
            /*
             * storage/app ALTINDA DEĞİL: açılış betiği o dizinde `chown -R`
             * yapıyor ve salt okunur bağlanmış bir dosya bunu reddediyor;
             * konteyner açılışta düşüp yeniden başlatma döngüsüne girer.
             */
            $yol = '/etc/dc/sertifika-durum.json';

            if (! is_readable($yol)) {
                return null;
            }

            $veri = json_decode((string) file_get_contents($yol), true);

            return is_array($veri) && isset($veri['kalan_gun']) ? $veri : null;
        });
    }

    /**
     * Uyarı basılmalı mı?
     *
     * İç CA'da basılmaz: Caddy kendi sertifikasını kendi yeniliyor, kullanıcının
     * yapacağı bir şey yok ve her ay çıkan bir uyarı okunmaz hâle gelirdi.
     */
    public static function uyarilmali(): bool
    {
        $d = self::oku();

        return $d !== null
            && ($d['ic_ca'] ?? false) === false
            && (int) $d['kalan_gun'] < self::ESIK;
    }
}
