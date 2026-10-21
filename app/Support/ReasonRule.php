<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Credential;

/**
 * "Bu işlemde gerekçe sorulur mu?" sorusunun TEK yanıtı.
 *
 * Gerekçe her işlemde zorunlu değildir. Bir web sunucusunun konsolunu açmak
 * için her seferinde "neden" yazmak insanları kutuya "test", "bakım", "."
 * yazmaya iter; alan dolar, denetim hiçbir şey öğrenmez.
 * Az sayıda kayıtta sorulan gerekçe, her kayıtta sorulandan daha çok bilgi
 * taşır.
 *
 * KARAR KAYDIN KENDİSİNDE: `credentials.is_critical` (bkz. göç
 * 2026_09_15_200000). Kayıt formundan tek tek işaretlenir.
 *
 * İKİ UÇTA DA AYNI YANIT. Onay penceresi alanı gizlemek için `ister()`e
 * bakar, sunucu da aynı yanıta göre kural kurar. Kural yalnızca ekranda
 * yaşasaydı isteği elle kuran gerekçesiz geçerdi; yalnızca sunucuda
 * yaşasaydı kullanıcı doldurduğu alanın neden reddedildiğini anlamazdı.
 * İki yere AYRI AYRI yazılsaydı biri değişince öteki sessizce eskirdi — bu
 * panellerde en pahalı hata sınıfı budur.
 *
 * TOPLU İŞLEMLER AYRI: bkz. toplu().
 */
final class ReasonRule
{
    /** Anlamlı bir cümlenin en azı; "." ya da "ok" geçmesin. */
    public const EN_AZ = 3;

    /** Denetim satırına sığacak en çoğu. */
    public const EN_COK = 190;

    /**
     * Bu KAYITTA gerekçe isteniyor mu?
     *
     * Kayıt yoksa (uuid tutmadı, silinmiş) istenmez: isteği zaten yetki ya da
     * "bulunamadı" düşürecek, gerekçe hatası kullanıcıyı yanlış yere bakmaya
     * gönderirdi.
     */
    public static function ister(?Credential $credential): bool
    {
        return (bool) $credential?->is_critical;
    }

    /**
     * TEK KAYITLIK işlemlerin doğrulama kuralı.
     *
     * Kritik değilse alan yine KABUL EDİLİR (kullanıcı yazmak isterse yazar,
     * kayda geçer) ama zorunlu değildir.
     *
     * @return array<int, string>
     */
    public static function kayit(?Credential $credential): array
    {
        return [
            self::ister($credential) ? 'required' : 'nullable',
            'string',
            'min:'.self::EN_AZ,
            'max:'.self::EN_COK,
        ];
    }

    /**
     * TOPLU işlemlerin kuralı: HER ZAMAN istenir.
     *
     * Parolaları dosyaya çıkarmak, toplu parola güncellemek, grup silmek —
     * bunlar tek bir kaydın işi değildir. Hangi kayda bakılacağı belli
     * olmadığı için "kritik mi" sorusunun bir yanıtı da yoktur; üstelik
     * kapsamı en geniş, geri dönüşü en zor işlemler bunlar.
     *
     * @return array<int, string>
     */
    public static function toplu(): array
    {
        return ['required', 'string', 'min:'.self::EN_AZ, 'max:'.self::EN_COK];
    }
}
