<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\KimlikDurumunuDogrula;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleApiClients;
use App\Services\CihazArayuzu\ArayuzOturumu;
use App\Services\CihazArayuzu\ArayuzTuru;
use App\Services\ErrorLogger;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',

        /*
         * API AYRI BİR KAPIDIR: oturum çerezi taşımaz, CSRF beklemez ve
         * kimliğini yalnızca jetondan alır (bkz. config/auth.php).
         */
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',

        /*
         * Sağlık ucu. Konteyner bunu her 30 saniyede bir yokluyor; yanıt
         * vermezse docker paneli ÖLÜ sayar ve yeniden başlatır.
         *
         * Kimlik doğrulaması YOK ve olmamalı: sağlık yoklaması oturum
         * açamaz. Uç hiçbir veri döndürmez, yalnızca uygulamanın ayakta
         * olduğunu söyler.
         */
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TERS VEKİLE GÜVEN — konteyner kurulumunda ŞART.
         *
         * TLS önde duran vekilde (Caddy) sonlanıyor ve konteynere düz HTTP
         * geliyor. Bu satır olmadan uygulama isteği "http" sanar: ürettiği
         * bütün mutlak adresler http olur, Secure çerez gönderilmez ve
         * merkeze giden OAuth dönüş adresi kayıtlı adresle eşleşmediği için
         * takas "redirect_uri mismatch" ile reddedilir.
         *
         * Liste '*' DEĞİL: X-Forwarded-For'a güvenmek onu YAZILABİLİR kılar.
         * Hız sınırları ve denetim kayıtları istemci adresine bakıyor; herkese
         * güvenilseydi kendi adresini yazan biri ikisini de atlatırdı.
         * Varsayılan özel ağlardır (docker köprüsü 172.16/12 içinde kalır);
         * başka bir yerleşimde TRUSTED_PROXIES ile daraltılır.
         */
        /*
         * CİHAZ ARAYÜZÜ uçları için ASGARİ katman.
         *
         * "web" grubunun oturum katmanı burada ZARARLI: her istekte panelin
         * oturumunu açıp çerezini yeniliyor ve vekil kökeni panelle aynı alan
         * adında olduğu için (yalnızca port farklı, çerezler portu umursamaz)
         * cihaz arayüzünün istekleri panelin oturum çerezini eziyor —
         * kullanıcı panelden düşüyordu.
         *
         * Bu uçların oturuma ihtiyacı yok: kimliği kendi çerezleri taşıyor.
         * Geriye yalnızca çerezin çözülmesi ve yanıta eklenmesi kalıyor.
         */
        $middleware->group('cihaz-arayuzu', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
        ]);

        /*
         * Cihazın kendi çerezleri ŞİFRELENMEZ.
         *
         * Laravel her çerezi şifreler ve bu doğrudur — panelin çerezleri için.
         * Ama vekil kökeninde cihazın kendi çerezleri de duruyor ve onları
         * okuyan taraf cihazın betiği: şifrelenmiş değeri tanımaz. Adlar tek
         * yerde, türün kendisinde sayılır (ArayuzTuru::tarayiciCerezleri).
         */
        $middleware->encryptCookies(except: ArayuzTuru::sifrelenmeyenCerezler());

        /*
         * AYRILIŞ HABERİ CSRF JETONU TAŞIYAMAZ.
         *
         * Haber sayfa kapanırken sendBeacon ile atılıyor; beacon'a başlık
         * eklenemiyor ve cihazın sayfasında panelin jetonu zaten yok. Uç
         * KİMLİK DOĞRULAR: oturumun kendi çerezi olmadan hiçbir şey yapmaz ve
         * yaptığı tek şey "sesi kesildi" işareti koymak — kötüye kullanılsa
         * bile kazanç, kendi oturumunu erken kapatmaktır.
         */
        $middleware->validateCsrfTokens(except: [
            ltrim(ArayuzOturumu::ONEK, '/').'/ayril',
        ]);

        $middleware->trustProxies(
            at: array_filter(explode(',', (string) env(
                'TRUSTED_PROXIES',
                '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16',
            ))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->redirectGuestsTo(fn () => route('login'));

        /*
         * Merkezle giren kişinin merkezdeki durumu HÂLÂ geçerli mi?
         *
         * Merkezde rolü değişen ya da hesabı askıya alınan biri, oturumu açık
         * olduğu için burada erişmeye devam ediyordu. Kural her web isteğini
         * kapsar ama merkeze her istekte sorulmaz: yanıt kullanıcı başına
         * önbelleklenir (bkz. KimlikDurumunuDogrula).
         */
        /*
         * PASİF HESAP İÇERİDE KALMAZ.
         *
         * Durumu değiştirmek yalnızca "bir daha giremesin" demekti; açık
         * oturum çalışmaya devam ediyordu. Oturum satırlarını silmek (bkz.
         * OturumlariKapatir) anında etkili ama yalnızca veritabanı
         * oturumunda ve model olayı üzerinden çalışıyor; bu ara katman her
         * istekte sorar ve hiçbir yolu atlamaz.
         *
         * SIRA ÖNEMLİ: önce yerel durum, sonra merkez sorgusu. Pasif hesabı
         * dışarı atmak için merkeze gitmeye gerek yok ve merkez kapalıyken
         * de çalışmalı.
         */
        /*
         * OTURUM PAROLAYA BAĞLANIR (AuthenticateSession).
         *
         * Auth::logoutOtherDevices() yalnızca parola özetini yeniler; diğer
         * oturumları düşüren, her istekte oturumdaki özeti kullanıcınınkiyle
         * karşılaştıran bu ara katmandır. Kayıtlı değildi: parolayı kendisi
         * değiştiren de, yöneticinin sıfırladığı da eski oturumlarla
         * girmeye devam ediyordu. Sürücüden bağımsız çalışır (veritabanı,
         * redis, dosya) ve oturum süresine/boşta kalma kuralına dokunmaz.
         *
         * EnsureUserIsActive'ten ÖNCE: parolası değişmiş oturum, durum
         * sorusuna varmadan düşer.
         */
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            KimlikDurumunuDogrula::class,
        ]);

        /*
         * Panelin sayfaları ÇERÇEVEYE ALINAMAZ.
         *
         * Güvenlik başlıkları olmadan herhangi bir site paneli görünmez bir
         * çerçeveye alıp kullanıcının tıklamasını "parolayı göster" düğmesine
         * yönlendirebilir.
         */
        /*
         * GENEL yığına eklenir, "web" grubuna DEĞİL.
         *
         * Gruba eklendiğinde en içte kalıyor: kimlik doğrulaması düştüğünde
         * fırlatılan istisna oradan yukarı geçip yanıtı istisna işleyicisi
         * kuruyor ve o yanıt bir daha ara katmandan geçmiyor — giriş
         * yönlendirmesi ve hata sayfaları başlıksız kalırdı (ör. /erisim'in
         * girişe yönlendiren 302'si).
         */
        $middleware->append(SecurityHeaders::class);

        /*
         * Kimliği DOĞRULANAMAYAN API isteği de sınırlanır.
         *
         * Jeton başına sınır kimlik doğrulamasından sonra uygulanıyor
         * (routes/api.php); jetonsuz gelen istek oraya hiç varmıyor ve
         * sınırsız denenebilirdi.
         */
        $middleware->api(prepend: [ThrottleApiClients::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Hatalar panele de yazılır. Dosya günlüğü kapanmaz; bu ona EK bir
         * görünüm — kuyrukta patlayan bir döndürmeyi sunucuya girmeden
         * görebilmek için.
         */
        $exceptions->report(function (Throwable $e): void {
            app(ErrorLogger::class)->record($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
