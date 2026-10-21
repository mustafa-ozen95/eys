<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Controllers\Middleware;

/**
 * İstek sınırı ara katmanı — ya da hiç.
 *
 * SIFIR "SINIR YOK" DEMEKTİR ve bunun için ara katman HİÇ EKLENMEZ:
 * `throttle:0,1` yazmak sınırı kaldırmaz, dakikada SIFIR isteğe indirir ve
 * ucu tamamen kapatır. Aynı kalıp giriş rotasında da kullanılıyor
 * (bkz. routes/web.php).
 *
 * Karar tek yerde: beş ayrı denetleyicide aynı "sıfırsa ekleme" koşulunu
 * tekrarlamak, birini değiştirip diğerlerini unutmanın yoluydu.
 */
final class IstekSiniri
{
    /**
     * @param  ?array<int, string>  $only  Yalnızca bu eylemlerde uygulanır.
     * @return array<int, Middleware>
     */
    public static function icin(string $ad, ?array $only = null): array
    {
        $sinir = (int) config('limits.'.$ad);

        if ($sinir <= 0) {
            return [];
        }

        $katman = new Middleware('throttle:'.$sinir.',1', only: $only);

        return [$katman];
    }
}
