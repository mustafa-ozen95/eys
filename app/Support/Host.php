<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Kayıttaki "adres" alanı gerçekten bir ağ adresi mi?
 *
 * Değer üç yere METİN olarak gömülüyor: SSH/uzak masaüstü hedefine, cihaz
 * arayüzünün adresine ve root ile yeniden yüklenen nginx havuz
 * yapılandırmasına. Sonuncusunda `;` ya da `}` içeren bir adres
 * yapılandırma yönergesi enjekte edebilirdi; serbest metin kabul edilmez.
 * Kural tek yerde durur; hem kayıt yazılırken hem yapılandırma
 * üretilirken aynısı sorulur.
 *
 * Kabul: alan adı (etiket başına 63, toplam 253; alt çizgi içerik adlarında
 * var), IPv4, IPv6 (köşeli parantezli ya da parantezsiz). Başka hiçbir şey.
 */
final class Host
{
    private const ETIKET = '[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?';

    public static function gecerliMi(string $konak): bool
    {
        if ($konak === '' || strlen($konak) > 255) {
            return false;
        }

        $cekirdek = $konak;

        if (str_starts_with($konak, '[') && str_ends_with($konak, ']')) {
            $cekirdek = substr($konak, 1, -1);

            return filter_var($cekirdek, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (filter_var($cekirdek, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return strlen($cekirdek) <= 253
            && preg_match('/^'.self::ETIKET.'(?:\.'.self::ETIKET.')*\.?$/D', $cekirdek) === 1;
    }

    /** "https://konak:port/yol" biçimindeki adresin konağı geçerli mi? (Boş konak geçersizdir.) */
    public static function adresGecerliMi(string $adres): bool
    {
        $parca = parse_url($adres);

        return $parca !== false && self::gecerliMi((string) ($parca['host'] ?? ''));
    }
}
