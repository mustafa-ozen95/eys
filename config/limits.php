<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| İstek Sınırları (dakikada kaç istek)
|--------------------------------------------------------------------------
|
| Bu sayılar KEYFİ DEĞİL: her biri o ucun dünyaya ne kadar dokunduğuna göre
| seçildi. Toplu döndürme tek istekte onlarca makineye bağlanır, tek kayıt
| döndürme bir makineye, portal oturumu ise yalnızca bir forma.
|
| Buraya taşınmalarının nedeni görsel test koşusu: senaryolar aynı uçlara
| art arda girdiği için koşu ortada 429 alıyor ve testler kodla ilgisiz
| biçimde düşüyordu.
|
| SIFIR = SINIR YOK. Ara katman o zaman hiç eklenmez; "throttle:0,1" yazmak
| sınırı kaldırmaz, ucu tamamen kapatırdı (bkz. App\Support\RequestLimit).
|
*/

return [
    /*
    | Tek kaydın parolasını güncelleme — SINIRSIZ.
    |
    | Dakikalık bir sınır gündelik kullanımda engel oluyor: birkaç kaydı arka
    | arkaya güncellemek ya da bir turu yeniden denemek 429 ile durur. Uç zaten
    | yetki, gerekçe ve cihaz başına tekillik kilidiyle korunuyor; sayı
    | saymanın eklediği bir güvence yoktu.
    */
    /*
     * Toplu parola çıktısı. Tek istek kasanın tamamını dosyaya yazabilir;
     * ele geçmiş bir hesap bunu arka arkaya çekmesin.
     */
    'export' => (int) env('LIMIT_EXPORT', 3),

    'rotate' => (int) env('LIMIT_ROTATE', 0),

    /* Toplu güncelleme: tek istek onlarca makineye dokunabilir. */
    'rotate_bulk' => (int) env('LIMIT_ROTATE_BULK', 3),

    /* Yarım kalmış turu onarma — aynı gerekçeyle sınırsız. */
    'rotate_repair' => (int) env('LIMIT_ROTATE_REPAIR', 0),

    /* Portal oturumu açma. */
    'portal' => (int) env('LIMIT_PORTAL', 10),

    /* Parolayı ekranda gösterme. */
    'reveal' => (int) env('LIMIT_REVEAL', 20),
];
