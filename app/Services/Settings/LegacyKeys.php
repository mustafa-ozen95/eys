<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Veritabanında kayıtlı eski ayar anahtarlarının ÇEVİRİSİ.
 *
 * Ayarın anahtarı config yolunun kendisidir; config dosyaları İngilizce
 * adlandırılınca anahtarların ilk parçası da değişti. `settings` tablosundaki
 * satırlar ise bu adlarla yazılmıştı ve yeniden adlandırılmaz (canlı veri):
 * çeviri YALNIZCA veritabanı sınırında yapılır. Okurken eski ad yeniye,
 * yazarken yeni ad eskiye döner; uygulamanın geri kalanı yalnızca yeni adı bilir.
 * Önbellekte kalmış eski adlı bir dizi de aynı çeviriden geçer.
 */
final class LegacyKeys
{
    /** Eski ilk parça → yeni ilk parça. */
    private const PREFIXES = [
        'kimlik' => 'identity',
        'teklif' => 'quote',
        'basit_mod' => 'simple_mode',
    ];

    /** Veritabanındaki ad → uygulamadaki ad. */
    public static function toCurrent(string $key): string
    {
        return self::swap($key, self::PREFIXES);
    }

    /** Uygulamadaki ad → veritabanındaki ad. */
    public static function toStored(string $key): string
    {
        return self::swap($key, array_flip(self::PREFIXES));
    }

    /**
     * Anahtarları çevrilmiş yeni bir dizi döner (değerlere dokunmaz).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function currentKeys(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            $out[self::toCurrent((string) $key)] = $value;
        }

        return $out;
    }

    /** @param  array<string, string>  $map */
    private static function swap(string $key, array $map): string
    {
        $dot = strpos($key, '.');

        if ($dot === false) {
            return $key;
        }

        $head = substr($key, 0, $dot);

        return isset($map[$head]) ? $map[$head].substr($key, $dot) : $key;
    }
}
