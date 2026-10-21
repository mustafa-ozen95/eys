<?php

declare(strict_types=1);

namespace App\Services\Rotation;

/**
 * Cihaza ULAŞILAMADI — cihaz "hayır" demedi.
 *
 * NEDEN AYRI BİR İSTİSNA: `verify()` bir boole döndürüyor ve sürücüler her
 * hatayı `false`a çeviriyordu. O `false` çağıran yerde "parola yanlış" diye
 * okunuyor.
 *
 * Örnek: Lantronix Spider art arda gelen bağlantıları kısa süre reddeder.
 * Parola CİHAZDA değişir, doğrulama isteği bağlantı hatası alır; sürücü
 * `false` döndürseydi tur "yeni parola çalışmıyor" diyerek kasayı
 * güncellemezdi. Kasada eski, cihazda yeni parola kalırdı — bir parola kasası
 * için en kötü sonuç, üstelik fark ancak bir sonraki girişte anlaşılırdı.
 * (Aynı parola kısa bir bekleyişten sonra sorunsuz doğrulanır.)
 *
 * Bu yüzden ulaşılamamak `false` DEĞİLDİR: yukarı fırlatılır, tur "cihaza
 * ulaşılamadı" diye kapanır ve denenen parola kayıtta durduğu için onarım
 * ayrışmayı kapatabilir.
 */
class DeviceUnreachableException extends RotationException {}
