<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\User;
use App\Services\DeviceInterface\InterfaceSession;
use App\Services\DeviceInterface\InterfaceType;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Cihaz arayüzü vekilinin bekçisi.
 *
 * nginx her isteği önce buraya sorar ve HEDEFİ de buradan öğrenir
 * (X-Eys-Hedef). Hedefin isteğin kendisinden değil BİZİM KAYDIMIZDAN gelmesi
 * şarttır: Host Client istediği konağı `x-vsphere-proxy` başlığında yolluyor
 * ve o başlığa güvenilseydi panel, istenen her adrese bağlanan açık bir vekile
 * dönerdi. Başlık burada yalnızca "hangisini istiyorsun" sorusunun cevabıdır;
 * karşılığı kayıtta yoksa 403.
 *
 * Yetki HER istekte yeniden sorulur: oturum açıldıktan sonra kullanıcının
 * yetkisi geri alınmış ya da hesabı kapatılmış olabilir.
 *
 * "auth" ara katmanının DIŞINDADIR: nginx auth_request yalnızca 2xx/401/403
 * anlar, yönlendirmeyi 500'e çevirir.
 */
class InterfaceProxyWatcher extends Controller
{
    public function __construct(private InterfaceSession $oturumlar) {}

    public function __invoke(Request $request): Response
    {
        /*
         * KİMLİK PANELİN OTURUMUNDAN SORULMAZ.
         *
         * Vekil ayrı bir alan adında durabiliyor ve panelin oturum çerezi
         * konak-özeldir — oraya hiç gitmez, gitmemeli de: gitseydi cihazın
         * arayüzünden çıkan betik panele, oturumu açan kişi adına istek
         * atabilirdi. Kimlik bunun yerine vekil kökenindeki çerezle taşınır
         * ve KİM OLDUĞU kaydın kendisinden okunur.
         */
        $kayit = $this->kayit($request);

        if ($kayit === null) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $sahip = User::query()->find($kayit['user_id']);

        /*
         * Yetki HER istekte yeniden sorulur: oturum açıldıktan sonra kişinin
         * yetkisi geri alınmış ya da hesabı kapatılmış olabilir. Açık duran
         * bir arayüzün çalışmaya devam etmesi, hesabı kapatmayı etkisiz
         * kılardı.
         */
        if ($sahip === null || ! $sahip->isActive()) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $credential = Credential::query()->find($kayit['credential_id']);

        /* Sorulan yetenek "arayuz": vekilde parola tarayıcıya hiç gitmiyor. */
        if ($credential === null || $sahip->cannot('arayuz', $credential)) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $this->oturumlar->canlandir($kayit);

        /*
         * Hedefin YANINDA cihazdaki TABAN YOL da söylenir: ESXi'de arayüz
         * /ui/ altında, VAMI'de kökte duruyor. nginx bunu bilemez; bilseydi
         * de tür başına ayrı bir konum yazmak gerekirdi ve yeni bir arayüz
         * eklerken biri unutulurdu.
         */
        $tur = InterfaceType::from((string) $kayit['tur']);

        $onek = $tur->yolOnekiKullanir() && InterfaceSession::ayriKoken()
            ? InterfaceSession::cihazOneki($credential)
            : '';

        return response()->noContent()
            ->header('X-Eys-Hedef', (string) $kayit['hedef'])
            /*
             * OTURUMUN KENDİSİ. Vekil, cihazdan gelen her sayfaya açılış
             * betiğini bu oturumun adresiyle ekliyor; betik böylece hangi
             * oturumda olduğunu biliyor ve adres çubuğu önek dışına düşmüşse
             * geri yazabiliyor. Oturumu ÇEREZDEN bulsaydı iki sekmede iki
             * arayüz açıkken yanlış oturumu bulabilirdi.
             */
            ->header('X-Eys-Oturum', (string) $kayit['token'])
            /*
             * OTURUM ÖNEKİ — cihazın YÖNLENDİRMELERİ için.
             *
             * Cihaz "https://kendi-adresim/admin/" diye yönlendiriyor; vekil
             * kökeni bizimkiyle değiştiriyor ama önek düşüyordu ve tarayıcı
             * önek dışına çıkıyordu (Plesk'in kökü 303 ile /admin/ diyor).
             * Önek burada söyleniyor; önek kullanmayan türde
             * (Rspamd) boş kalır ve kural eskisi gibi çalışır.
             */
            ->header('X-Eys-Onek', $onek)
            /*
             * CİHAZIN KENDİ <base> ETİKETİ.
             *
             * vSphere'in sayfası "<base href=\"/ui/\">" yazıyor ve bu, bütün
             * göreli adresleri önekin DIŞINA, cihazın kök yoluna çekiyor:
             * oturum yine çereze düşüyor ve iki sekmede çakışma geri geliyor.
             * Kullanıcı bunu giriş ekranına atılmak olarak görür.
             *
             * Etiketin YENİ DEĞERİ burada hesaplanıyor: önek varsa önek,
             * yoksa cihazın kendi taban dizini — yani öneksiz kipte hiçbir şey
             * değişmiyor.
             */
            ->header('X-Eys-Base', $onek !== '' ? $onek.'/' : $tur->cihazYolu())
            ->header('X-Eys-Taban', $this->taban($tur, $credential, (string) $request->header('X-Original-Uri', '')))
            /*
             * Cihazın PORTSUZ adı. Yönlendirmeleri vekilde tutmak için gerekli:
             * cihazlar kendi adreslerini portlu da portsuz da kurabiliyor ve
             * yalnızca portlu biçime bakan kural tutmuyordu — tarayıcı vekilden
             * çıkıp cihazın kökenine düşüyordu (vCenter
             * "https://<cihaz-adresi>/websso/..." diyor).
             */
            ->header('X-Eys-Konak', (string) preg_replace('/:\d+$/', '', (string) $kayit['hedef']))
            /*
             * İstek cihazın kökeninden gelmiş görünsün mü? Yalnızca isteyen
             * türde (bkz. InterfaceType::kokenYazilirMi); vekil Referer ve
             * Origin başlıklarını o zaman yeniden kuruyor.
             */
            ->header('X-Eys-Koken', $tur->kokenYazilirMi() ? '1' : '')
            /*
             * Cihaza gidecek çerez. Sunucuda açılan oturumlarda cihazın kendi
             * çerezi; ötekilerde tarayıcınınkilerden YALNIZCA cihaza ait
             * olanlar. Panelin ve vekilin çerezleri hiçbir durumda geçmez.
             */
            ->header('X-Eys-Cerez', $this->oturumlar->cerezSuz($kayit, $request->header('Cookie')))
            /*
             * ÇEREZ DEĞİL BAŞLIK İSTEYEN ARAYÜZÜN SIRRI (Rspamd'da parolanın
             * kendisi). Boş dönerse nginx başlığı hiç göndermez — ötekiler
             * etkilenmez.
             */
            ->header('X-Eys-Gizli', (string) ($kayit['gizli'] ?? ''));
    }

    /**
     * Çerezin gösterdiği oturum — "kim" sorusunun cevabı.
     *
     * @return array<string, mixed>|null
     */
    private function cerezKaydi(Request $request): ?array
    {
        $token = $request->cookie(InterfaceSession::COOKIE);

        return is_string($token) ? $this->oturumlar->bul($token) : null;
    }

    /** İstek adresinin yolu; sorgu dizesi kararı ilgilendirmiyor. */
    private function yol(string $adres): string
    {
        return parse_url($adres, PHP_URL_PATH) ?: $adres;
    }

    /**
     * BU İSTEK İÇİN TABAN DİZİNİ.
     *
     * Arayüzün taban dizini vCenter'da "/ui/"; vekil onu her yola ekliyor.
     * Ama arayüzlerin CİHAZIN KÖKÜNDEN istediği yollar da var (/sdk,
     * /websso/, /apigw …) ve onlara taban EKLENMEZ.
     *
     * Öneksiz adreste bu ayrımı nginx yapıyordu: her kök yolun kendi konumu
     * var ve o konumlar tabanı hiç kullanmıyor. ÖNEK ALTINDA o konumlar
     * devrede değil — adres "/cihaz/ornek-cihaz/websso/…" biçiminde ve tek
     * bir konuma düşüyor. Ayrımı burada yapmak gerekiyordu:
     *
     *     /cihaz/ornek-cihaz/websso/SAML2/SLO/vsphere.local
     *       → /ui/websso/SAML2/SLO/… → 401 (SAML çıkışı)
     *       → /websso/SAML2/SLO/…    → doğru uç
     *
     * Öneksiz istekte de doğru çalışır: kök yolların kendi konumları tabanı
     * zaten kullanmıyor, ötekiler için de cihazın taban dizini dönüyor.
     */
    private function taban(InterfaceType $tur, Credential $credential, string $adres): string
    {
        $yol = $this->yol($adres);
        $onek = InterfaceSession::cihazOneki($credential);

        if (str_starts_with($yol, $onek)) {
            $yol = substr($yol, strlen($onek));
            $yol = $yol === '' ? '/' : $yol;
        }

        foreach ($tur->kokYollari() as $kok) {
            if (str_starts_with($yol, $kok)) {
                /*
                 * "/" döner, boş DEĞİL: nginx yolu ayırıcısız birleştiriyor
                 * ($arayuz_yol baştaki eğik çizgiyi taşımıyor) ve boş tabanla
                 * adres "https://havuzwebsso/…" oluyordu — 502.
                 */
                return '/';
            }
        }

        return $tur->cihazYolu();
    }

    /**
     * Adresin önekindeki kayıt.
     *
     * Ad UYDURULAMAZ: kimlik sondan okunuyor ve ad yeniden üretilip
     * karşılaştırılıyor (bkz. InterfaceSession::slugdanKayit). Ad doğru olsa bile
     * oturumu açan kişi olmadan işe yaramaz; kişiyi çerez söylüyor.
     */
    private function onektenKayit(string $adres): ?Credential
    {
        $onek = preg_quote(InterfaceSession::CIHAZ_ONEK, '#');

        if (preg_match('#^'.$onek.'/([a-z0-9-]+)(?:/|$)#i', $this->yol($adres), $eslesme) !== 1) {
            return null;
        }

        return InterfaceSession::slugdanKayit(mb_strtolower($eslesme[1]));
    }

    /**
     * Hangi oturum soruluyor.
     *
     * Üç yol var, hepsi de kendi adresinden geliyor:
     *  - /cihaz-oturum/<kimlik>/... : kimlik adreste,
     *  - /sdk                       : hedef `x-vsphere-proxy` başlığında,
     *  - /ui/... ve /rest/...       : ikisi de yok, çerezdeki kimlik kullanılır.
     *
     * @return array<string, mixed>|null
     */
    private function kayit(Request $request): ?array
    {
        /*
         * OTURUMU PORT SÖYLER — en güvenilir yol bu.
         *
         * Arayüz kendi portunda açılıyor ve cihazın MUTLAK adresleri bile o
         * portta kalıyor. Yol öneki bunu yapamıyordu: Plesk'in kendi
         * bağlantıları ("/admin/domain/list?context=domains") öneğin dışına
         * çıkıyor ve oturum yine çereze — yani son açılan arayüze — düşüyordu.
         * Bu yüzden her arayüz ayrı bir portta açılır.
         *
         * Port panele Host başlığından geliyor; öndeki ters vekil onu olduğu
         * gibi iletmeli.
         */
        $port = (int) $request->getPort();

        if (InterfaceSession::ilkPort() > 0
            && $port >= InterfaceSession::ilkPort()
            && $port <= InterfaceSession::sonPort()) {
            $portOturumu = $this->oturumlar->portIcin($port);

            /*
             * PORT OTURUMU SEÇER, KİMLİĞİ ÇEREZ KANITLAR.
             *
             * Port otuz değerden biri; tahmin edilebilir ve adres çubuğunda
             * görünür. Tek başına yeterli sayılırsa giriş yapmamış ya da
             * yetkisi olmayan biri de o adrese gelip cihaza ulaşırdı.
             *
             * Çerez GİZLİDİR: HttpOnly, konak-özel ve oturumun jetonunu
             * taşıyor. Aranan şey çerezin BU oturumun jetonu olması değil —
             * aynı tarayıcıda birden çok arayüz açık olabiliyor ve çerez
             * yalnızca en son açılanı gösteriyor — aynı KİŞİYE ait olması.
             */
            $cerezKaydi = $this->cerezKaydi($request);

            if ($portOturumu !== null
                && $cerezKaydi !== null
                && (int) $cerezKaydi['user_id'] === (int) $portOturumu['user_id']) {
                return $portOturumu;
            }

            /*
             * Port havuzdan geldiyse başka yola sapılmaz: çerezi olmayan ya da
             * başkasına ait bir istek, oturumu adresten tahmin ederek
             * ilerleyememeli.
             */
            return null;
        }

        $adres = (string) $request->header('X-Original-Uri', '');
        $onek = preg_quote(InterfaceSession::onek(), '#');

        $token = $request->cookie(InterfaceSession::COOKIE);
        $kayit = is_string($token) ? $this->oturumlar->bul($token) : null;

        /*
         * ADRESTEKİ OTURUM KİMLİĞİ TEK BAŞINA KANIT DEĞİLDİR.
         *
         * Kimlik adreste (ve Referer'da) taşınıyor; bir kez sızarsa (kayıt,
         * paylaşılan bağlantı, ekran görüntüsü) çerezi olmayan biri de
         * oturum sahibi gibi davranır ve bekçi ona hedefi, saklı sırrı ve
         * cihaz çerezini verirdi. Port kolu zaten çerezle kanıtlıyor; iki
         * kol da aynı kuralı uygular: çerezdeki oturum AYNI KİŞİYE ait
         * olmalı. Çerez gizli (HttpOnly) ve konak-özeldir.
         */
        if (preg_match('#^'.$onek.'/([0-9a-f-]{36})#i', $adres, $eslesme) === 1) {
            $adresteki = $this->oturumlar->bul($eslesme[1]);

            return $adresteki !== null
                && $kayit !== null
                && (int) $adresteki['user_id'] === (int) $kayit['user_id']
                    ? $adresteki
                    : null;
        }

        /*
         * ADRESTE KAYDIN ADI VARSA CİHAZI O BELİRLER.
         *
         * Önek artık oturum jetonu değil kaydın adını taşıyor
         * (/cihaz/ornek-panel/…). Ad KİMİN oturumu olduğunu söylemez; onu
         * çerez söylüyor. Yani adres CİHAZI, çerez KİŞİYİ belirliyor ve ikisi
         * birlikte tek bir oturuma işaret ediyor — iki Plesk iki sekmede
         * açıkken ayrımı yapan şey bu.
         *
         * Sayfanın adresine (Referer) de bakılır: arayüzler varlıklarını
         * kökten MUTLAK istiyor ("/cp/javascript/…") ve o istekler önekin
         * dışına düşüyor, ama isteği yapan sayfa önek altında.
         */
        $cihaz = $this->onektenKayit($adres)
            ?? $this->onektenKayit((string) $request->header('Referer', ''));

        if ($cihaz !== null && $kayit !== null) {
            $oturum = $this->oturumlar->kayitIcin((int) $kayit['user_id'], (int) $cihaz->getKey());

            if ($oturum !== null) {
                return $oturum;
            }
        }

        /*
         * ESKİ JETONLU ÖNEK — panelin kendi kökeninde adres hâlâ öyle.
         *
         * Arayüzler varlıklarını kökten MUTLAK istiyor ("/cp/javascript/…")
         * ve o istekler önekin dışına düşüyor. Ama isteği yapan SAYFA önek
         * altında duruyor ve tarayıcı aynı kökende tam adresi Referer'da
         * gönderiyor — yani oturum orada yazılı.
         *
         * Bu, çerezden ÖNCE gelir: çerez tarayıcıya ait, Referer ise sayfaya.
         * İki arayüz iki sekmede açıkken ayrımı yapabilen tek şey bu: yoksa
         * birinde sayfa değişince istek ötekinin cihazına gider.
         *
         * SAHİPLİK KORUNUR: çerezde bir oturum varsa, Referer'ın gösterdiği
         * oturum aynı kullanıcıya ait olmalı. Jeton zaten gizli ama iki
         * kapıdan birinin açık kalması yetmez.
         */
        $sayfa = (string) $request->header('Referer', '');

        if (preg_match('#^https?://[^/]+'.$onek.'/([0-9a-f-]{36})#i', $sayfa, $eslesme) === 1) {
            $refererli = $this->oturumlar->bul($eslesme[1]);

            /* Çerezi olmayan Referer ile oturum kuramaz (`$kayit === null` geçmez). */
            if ($refererli !== null
                && $kayit !== null
                && (int) $refererli['user_id'] === (int) $kayit['user_id']) {
                return $refererli;
            }
        }

        if ($kayit === null) {
            return null;
        }

        /*
         * Arayüz hangi cihazı istediğini `x-vsphere-proxy` başlığında
         * söylüyor. Başlığa GÜVENİLMEZ — güvenilseydi panel, istenen her
         * adrese bağlanan açık bir vekile dönerdi. Başlık yalnızca çerezdeki
         * oturumla KARŞILAŞTIRILIR; tutmuyorsa bağlanılmaz.
         */
        $istenen = $this->oturumlar->basliktanKonak($request->header('X-Vsphere-Proxy'));

        if ($istenen !== null && strcasecmp($istenen, (string) $kayit['hedef']) !== 0) {
            return $this->oturumlar->konakIcin((int) $kayit['user_id'], $istenen);
        }

        /*
         * YOL BİR TÜRE ÖZGÜYSE ÇEREZ DEĞİL YOL KARAR VERİR.
         *
         * Çerez sekmeye değil tarayıcıya ait: kullanıcı iki arayüzü iki
         * sekmede açtığında son açılan kazanıyor ve eski sekmenin istekleri
         * yanlış cihaza gidiyor. Örnek: Plesk ve vCenter aynı anda açıkken
         * vSphere'in "New Virtual Machine" sihirbazı `/apigw` isteğini
         * Plesk'e gönderir ve Plesk'in 404 sayfasını alır.
         *
         * `/sdk` bu tuzağa düşmüyor: vSphere orada hedefi `x-vsphere-proxy`
         * başlığında söylüyor. Ötekiler ne o başlığı ne Referer'ı taşıyor
         * (günlükte referer alanı boş), geriye tek ipucu yolun kendisi
         * kalıyor.
         *
         * KURAL TEK YÖNLÜ: yolun sahibi varsa ve çerezdeki oturum o sahiplerden
         * DEĞİLSE, kullanıcının o türden açık oturumuna geçilir. Çerez zaten
         * doğru aileyi gösteriyorsa hiçbir şey değişmez — aynı aileden iki
         * oturum arasında seçim yapmaya kalkmıyoruz.
         */
        $tur = InterfaceType::from((string) $kayit['tur']);
        $sahipler = InterfaceType::yolunSahipleri($this->yol($adres));

        if ($sahipler !== [] && ! in_array($tur, $sahipler, true)) {
            return $this->oturumlar->turIcin((int) $kayit['user_id'], $sahipler) ?? $kayit;
        }

        return $kayit;
    }
}
