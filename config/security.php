<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Güvenlik başlıkları
|--------------------------------------------------------------------------
| Her yanıta eklenir (bkz. App\Http\Middleware\SecurityHeaders). Değerler
| burada tutulur ki hangi kısıtın neden konduğu tek yerden okunabilsin.
|
| İçerik CSP'si `script-src 'self'` taşır: satır içi <script> bloğu sessizce
| çalışmaz ve ekran bozulur. Bu yüzden her betik dosyadan yüklenir (ör.
| portal-session.blade.php → public/session-open.js); yeni bir görünüm de satır
| içi betik eklememeli.
*/

return [

    'headers' => [

        /*
        | Blade'de satır içi script ve style BIRAKILMADI (ölçüldü),
        | bu yüzden 'unsafe-inline' gerekmiyor — gerekseydi politikanın en
        | önemli kısmı anlamını yitirirdi. Yazı tipleri Google Fonts'tan
        | gelir; başka hiçbir dış kaynağa izin yok.
        |
        | frame-src 'self': uzak oturum penceresi kendi kökenimizdeki
        | ttyd/Guacamole vekilini gömer.
        |
        | frame-ancestors 'self': panel çerçeveye alınamaz. Cihaz arayüzü
        | vekili bu kuraldan MUAF; gerekçe SecurityHeaders'ın başındaki uzun
        | yorumda.
        |
        | FORM-ACTION BİLEREK YOK.
        |
        | Portal fırlatma sayfası parolayı kullanıcının kendi portalına POST
        | ediyor ve o adres kayıttan geliyor, yani önceden bilinemez
        | (bkz. credentials/portal-session.blade.php). "'self' *" yazmak
        | kısıtlama gibi görünüp hiçbir şeyi kısıtlamazdı; olmayan bir kuralı
        | var göstermek, kuralı hiç koymamaktan kötüdür. base-uri yerinde
        | duruyor ve <base> enjeksiyonunu kapatıyor.
        */
        'Content-Security-Policy' => "default-src 'self'; "
            ."script-src 'self'; "
            ."style-src 'self' https://fonts.googleapis.com; "
            ."font-src 'self' https://fonts.gstatic.com; "
            ."img-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-src 'self'; "

            /*
             * İŞÇİ (worker) blob'dan kurulur.
             *
             * `worker-src` yazılmadığında tarayıcı `script-src`e düşüyor ve
             * blob engelleniyordu: portal fırlatmasının zamanlayıcısı sessizce
             * hiç çalışmıyor, ikinci vCenter açılışı hata sayfasında kalıyordu
             * (ölçüldü). Blob işçisi kendi kökenimizden üretiliyor;
             * dışarıdan betik çalıştırma yolu açmıyor.
             */
            ."worker-src 'self' blob:; "
            ."frame-ancestors 'self'; "
            ."base-uri 'self'; "
            ."object-src 'none'",

        /* Yukarıdakini tanımayan eski tarayıcılar için. */
        'X-Frame-Options' => 'SAMEORIGIN',

        /* Tarayıcı içerik türünü tahmin etmesin. */
        'X-Content-Type-Options' => 'nosniff',

        /*
        | Adres başka kökene taşınmaz. Cihaz oturumunun kimliği adreste
        | duruyor (/cihaz-oturum/<jeton>/); dışarı çıkarken yol gitmemeli.
        */
        'Referrer-Policy' => 'same-origin',

        'X-Permitted-Cross-Domain-Policies' => 'none',

        /*
        | HSTS KAPALI — ve BİLEREK max-age=0 GÖNDERİLİYOR.
        |
        | Başlık, tarayıcıya "bu adrese bir daha asla http ile gitme" der ve
        | bunu KENDİ İÇİNDE SAKLAR. Sertifika bir gün düşerse (süresi dolar,
        | yenileme gecikir) tarayıcı uyarı ekranını bile göstermiyor:
        | "Yine de devam et" düğmesi HSTS'li adreslerde YOKTUR. Yani panele
        | hiçbir şekilde ulaşılamaz; varsayılan bu yüzden kapalıdır.
        |
        | Başlığı SİLMEK YETMEZ: tarayıcıda zaten yerleşmiş kayıt bir yıl
        | daha yaşar. `max-age=0` o kaydı SİLDİRİR. Değer bu yüzden burada
        | duruyor; kaldırılırsa eski tarayıcılar kilitli kalır.
        |
        | Karşılığında ne kaybediliyor: ilk istek http ile yapılırsa araya
        | girme penceresi. http zaten https'e yönlendiriliyor; bu risk, panele
        | hiç girememe riskinden küçük kabul edilir.
        */
        'Strict-Transport-Security' => 'max-age=0',

        /*
        | Kullanılmayan tarayıcı yetenekleri kapatılır. Pano açıkta bırakılır:
        | uzak oturum penceresinde kopyala/yapıştır gerekiyor.
        */
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), '
            .'payment=(), usb=(), magnetometer=(), gyroscope=(), autoplay=()',
    ],
];
