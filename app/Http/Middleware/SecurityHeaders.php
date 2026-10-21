<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Güvenlik başlıkları.
 *
 * nginx'te değil uygulamada durur: vekil değişince başlıkların kaybolmaması
 * için. Değerler config/security.php içindedir.
 *
 * Başlıklar olmasa herhangi bir site paneli görünmez bir çerçeveye alıp
 * üstüne kendi düğmesini koyabilir, kullanıcı ona bastığında tıklama
 * paneldeki düğmeye gidebilirdi. Burası bir parola kasası: o düğme
 * "parolayı göster" olabilir.
 *
 * ÇERÇEVE KİLİDİ HER YERDE DEĞİL.
 *
 * Cihaz arayüzü vekili paneli DEĞİL, cihazın kendi arayüzünü veriyor ve panel
 * onu bilerek çerçeveye alıyor — üstelik vekil ayrı kökende durabiliyor (ayrı
 * port da ayrı kökendir). O uçlara çerçeve kilidi koymak bütün cihaz
 * arayüzlerini kapatırdı.
 *
 * VAR OLAN BAŞLIK EZİLMEZ.
 *
 * Bir yanıt kendi kararını yazdıysa burada gevşetmek, o kararı sessizce geri
 * almak olur. Örnek: portal fırlatma sayfası bilerek
 * `Referrer-Policy: no-referrer` koyuyor — parolayı POST ettiği hedef bizim
 * adresimizi öğrenmesin diye; ezilseydi `same-origin`e düşerdi (bkz.
 * PortalSessionTest).
 *
 * Ayrım ROTANIN KENDİSİNDEN okunur, elle tutulan bir yol listesinden değil:
 * o uçlar zaten `cihaz-arayuzu` grubunda duruyor. (Grup `web`in YERİNE
 * geçmiyor, ona EKLENİYOR — route:list ikisini de gösteriyor — bu yüzden ayrımı ara katmanın kendisi yapmak zorunda.)
 */
class SecurityHeaders
{
    /** Çerçeve kilidinden muaf olan rota grubu. */
    public const SERBEST_GRUP = 'cihaz-arayuzu';

    /** Yalnızca çerçevelemeyi ilgilendiren başlıklar. */
    private const CERCEVE = ['X-Frame-Options', 'Content-Security-Policy'];

    public function handle(Request $request, Closure $next): Response
    {
        $yanit = $next($request);
        $serbest = $this->cerceveyeAcik($request);

        foreach ((array) config('security.headers') as $ad => $deger) {
            if (blank($deger)
                || $yanit->headers->has($ad)
                || ($serbest && in_array($ad, self::CERCEVE, true))) {
                continue;
            }

            $yanit->headers->set($ad, $deger);
        }

        $this->onbellekKapat($request, $yanit);

        return $yanit;
    }

    /**
     * OTURUM AÇIK SAYFALAR DİSKE YAZILMAZ.
     *
     * İki sebep, ikisi de gerçek:
     *
     * 1. GÜVENLİK. Burası bir parola kasası. Sayfada kullanıcı adları,
     *    adresler ve "göster"e basıldığında parolanın kendisi duruyor.
     *    `no-cache` tarayıcıya "kullanmadan önce sor" der ama KOPYAYI
     *    DİSKTEN SİLMEZ; ortak bir makinede çıkış yapıldıktan sonra da o
     *    dosya orada kalır. `no-store` hiç yazmamasını söyler.
     *
     * 2. ESKİ SAYFA. `no-cache` geri/ileri önbelleğini (bfcache) kapatmaz:
     *    kullanıcı geri gidip geldiğinde tarayıcı sayfayı SUNUCUYA HİÇ
     *    SORMADAN bellekten çizer. Panelde bir düğme eklendiğinde kullanıcı
     *    onu göremez ve haklı olarak "koymamışsın" der — sunucu koymuştur.
     *    `no-store` bu yolu da kapatır.
     *
     * YALNIZCA HTML ve yalnızca OTURUM AÇIKKEN: varlıklar (css/js) uzun
     * ömürlü önbelleğe muhtaç, giriş sayfasının da saklanacak bir şeyi yok.
     *
     * Var olan başlık yine ezilmez (bkz. yukarısı): parola ucu kendi
     * `no-store`unu zaten yazıyor.
     */
    private function onbellekKapat(Request $request, Response $yanit): void
    {
        /*
         * "Zaten var mı" diye BAKILMAZ: Laravel oturumlu her yanıta
         * kendiliğinden `no-cache, private` yazıyor, yani başlık her zaman
         * dolu olurdu ve bu kural hiç çalışmazdı. Bakılacak olan, yanıtın
         * BİLEREK no-store demiş olmasıdır — o zaman dokunulmaz.
         */
        if ($request->user() === null) {
            return;
        }

        if (str_contains((string) $yanit->headers->get('Cache-Control', ''), 'no-store')) {
            return;
        }

        $tur = (string) $yanit->headers->get('Content-Type', '');

        if (! str_contains($tur, 'text/html')) {
            return;
        }

        $yanit->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $yanit->headers->set('Pragma', 'no-cache');
    }

    /**
     * Rota bilerek çerçeveye alınıyor mu?
     *
     * Rota HİÇ eşleşmediyse (404) kilit KONUR: bilmediğimiz bir yanıtı
     * çerçeveye açmak, yanılma payını yanlış tarafa bırakmak olurdu.
     */
    private function cerceveyeAcik(Request $request): bool
    {
        return in_array(self::SERBEST_GRUP, $request->route()?->middleware() ?? [], true);
    }
}
