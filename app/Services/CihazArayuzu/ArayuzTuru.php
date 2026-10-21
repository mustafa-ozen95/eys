<?php

declare(strict_types=1);

namespace App\Services\CihazArayuzu;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;

/**
 * Panelin kendi kökeninden vekillediği cihaz arayüzleri.
 *
 * NEDEN VEKİL: bu arayüzlerin oturumu DIŞARIDAN açılamıyor. ESXi her SOAP
 * çağrısına istemcinin ürettiği bir CSRF jetonu koyuyor; VAMI oturum jetonunu
 * localStorage'da tutuyor. İkisi de başka bir kökenden kurulamaz. Arayüz
 * panelin kökeninden verilince ikisi de bizim tarafımızda olur ve giriş tutar.
 *
 * Türe göre DEĞİŞEN her şey burada: cihazdaki yol, kökten istenen yollar,
 * açılış betiği. Tek yerde durmazsa yeni bir arayüz eklerken biri
 * güncellenip diğeri unutulur.
 */
enum ArayuzTuru: string
{
    case Esxi = 'esxi';
    case Vami = 'vami';
    case Plesk = 'plesk';
    case Ilo = 'ilo';
    case Vcenter = 'vcenter';
    case DirectAdmin = 'directadmin';
    case Qnap = 'qnap';
    case Spider = 'spider';
    case Rspamd = 'rspamd';
    case Mailcow = 'mailcow';

    /**
     * Kayıt hangi arayüze ait? Hiçbirine değilse null.
     *
     * VAMI, PORTUNDAN tanınır: vCenter appliance yönetimi her kurulumda
     * 5480'de durur, değiştirilemez. Kaydın erişim türü "panel" olabiliyor
     * (kullanıcı öyle giriyor) ve ona bakmak ayrımı kaçırırdı.
     */
    public static function icin(Credential $credential): ?self
    {
        $tur = self::tanimla($credential);

        /*
         * Yalnızca AÇIK türler vekillenir. Kapalı bir tür için burada null
         * dönmek, o kaydın eski yoluna (formla giriş) dönmesi demektir; yarım
         * bir vekil çalışan bir girişi bozmamalı.
         */
        return $tur !== null && in_array($tur->value, (array) config('remote.arayuz.turler'), true)
            ? $tur
            : null;
    }

    private static function tanimla(Credential $credential): ?self
    {
        if ($credential->access_type === AccessType::Esxi) {
            return self::Esxi;
        }

        if ($credential->operating_system === OperatingSystem::Vcenter
            && self::portu($credential) === 5480) {
            return self::Vami;
        }

        if ($credential->access_type === AccessType::Vcenter) {
            return self::Vcenter;
        }

        if ($credential->operating_system === OperatingSystem::Bmc) {
            return self::Ilo;
        }

        /*
         * Plesk, GİRİŞ ADRESİNDEN tanınır. "login_up.php" Plesk'in sabit
         * giriş ucudur ve kayıtta zaten yazılı. Erişim türü "web", işletim
         * sistemi "application" olabiliyor — ikisi de Plesk'e özgü değil.
         * Sıfırlama sürücüsüne bakmak da olurdu ama o "nasıl sıfırlıyoruz"
         * sorusunun cevabı; burada sorulan "arayüzü nasıl açıyoruz".
         */
        if (str_contains((string) $credential->login_url, 'login_up.php')) {
            return self::Plesk;
        }

        if (self::directAdminMi($credential)) {
            return self::DirectAdmin;
        }

        /*
         * QNAP (QTS), GİRİŞ ADRESİNDEN tanınır — Plesk'teki kalıbın aynısı.
         * `authLogin.cgi` QTS'in sabit giriş ucudur; erişim türü "panel",
         * işletim sistemi "embedded" olabiliyor ve ikisi de QNAP'a özgü değil.
         */
        if (str_contains((string) $credential->login_url, 'authLogin.cgi')) {
            return self::Qnap;
        }

        /*
         * LANTRONIX SPIDER (SLS), GİRİŞ ADRESİNDEN tanınır — Plesk ve QNAP'ta
         * olduğu gibi. `auth.asp` cihazın sabit giriş ucu; erişim türü "panel",
         * işletim sistemi "embedded" ve ikisi de Spider'a özgü değil.
         *
         * Lantronix Spider'ın giriş yanıtı:
         *
         *     POST /auth.asp  login=…&password=…&action_login=Login
         *       → 302, Set-Cookie: pp_session_id=<64 onaltılık>; path=/
         *       → Location: /home.asp
         *
         * Yani klasik çerez oturumu: giriş sunucuda yapılır, çerez panelde
         * durur, parola tarayıcıya hiç inmez.
         */
        if (str_contains((string) $credential->login_url, '/auth.asp')) {
            return self::Spider;
        }

        /*
         * RSPAMD, ADRESİNDEN tanınır. mailcow onu her zaman `/rspamd` altında
         * veriyor ve arayüzün kendi yolu da orası.
         */
        if (str_contains((string) $credential->url, '/rspamd')) {
            return self::Rspamd;
        }

        /*
         * MAILCOW, CİHAZ AİLESİNDEN tanınır.
         *
         * Ötekilerde sabit bir giriş adresi var (login_up.php, authLogin.cgi,
         * auth.asp); mailcow'un giriş formu bulunduğu sayfaya POST ediyor ve
         * o sayfa yönetim kökü — yani adreste ayırt edici bir iz yok. Kayıtta
         * ZATEN yazılı olan alan ise doğru soruyu cevaplıyor: bu cihaz nedir.
         */
        if (str_contains(mb_strtolower((string) $credential->platform), 'mailcow')) {
            return self::Mailcow;
        }

        return null;
    }

    /**
     * DirectAdmin HESAP PORTALI (/clients) mı? Kaydın adresinden tanınır.
     *
     * Bu portal ötekilerden bir yönüyle ayrı: yönetilen bir cihaz değil,
     * üçüncü tarafın sitesi. Vekillenmesinin sebebi teknik bir zorunluluk —
     * portal giriş isteğinde `Referer` başlığını denetliyor ve o başlığı
     * TARAYICI yazdığı için panelden gönderilen bir form asla geçemiyor:
     *
     *     Referer: portalın kendi sayfası        → gövde "true"
     *     Referer yok ya da yabancı köken        → "Referer check has failed"
     *     ajax_login'siz düz gönderim            → giriş olmuyor
     *
     * Sunucuda giriş yapınca başlığı biz koyuyoruz ve parola tarayıcıya hiç
     * inmiyor — kaydı açan kişi parolayı ne görüyor ne kaydedebiliyor, ki bu
     * özelliğin varlık sebebi bu.
     *
     * BEDELİ: üçüncü tarafın sayfaları panelin kendi kökeninden servis
     * ediliyor. Ayrı bir alan adı daha yalıtık bir seçenektir.
     */
    private static function directAdminMi(Credential $credential): bool
    {
        foreach ([$credential->url, $credential->login_url] as $adres) {
            if (blank($adres)) {
                continue;
            }

            $konak = mb_strtolower((string) parse_url((string) $adres, PHP_URL_HOST));

            if (in_array($konak, ['directadmin.com', 'www.directadmin.com'], true)
                && str_contains((string) parse_url((string) $adres, PHP_URL_PATH), '/clients')) {
                return true;
            }
        }

        return false;
    }

    private static function portu(Credential $credential): ?int
    {
        if ($credential->port !== null) {
            return (int) $credential->port;
        }

        $parca = parse_url((string) $credential->url);

        return isset($parca['port']) ? (int) $parca['port'] : null;
    }

    /** Cihazda arayüzün durduğu yol. */
    public function cihazYolu(): string
    {
        return match ($this) {
            self::Esxi => '/ui/',
            self::Vcenter => '/ui/',
            self::Rspamd => '/rspamd/',
            default => '/',
        };
    }

    /**
     * İstek CİHAZIN KÖKENİNDEN gelmiş gibi görünsün mü?
     *
     * Tarayıcı `Referer` ve `Origin` başlıklarını kendi yazar ve vekilin
     * kökenini koyar. Çoğu arayüz buna bakmıyor; DirectAdmin portalı HER
     * istekte bakıyor ve panelin kökenini gördüğünde "Referer check has
     * failed" diyor — giriş çalışsa bile içinde gezilemez.
     *
     * Yalnızca isteyen türde açılır: çalışan bir arayüzü, ihtiyacı olmayan
     * bir düzeltmeyle bozmanın anlamı yok.
     */
    public function kokenYazilirMi(): bool
    {
        return $this === self::DirectAdmin;
    }

    /**
     * Devir sonrası tarayıcının GİDECEĞİ yol.
     *
     * Çoğu arayüzde taban yolun kendisi: kökten başlayıp kendi ekranına
     * gidiyorlar. DirectAdmin portalında ikisi AYRILMAK ZORUNDA — hesap
     * alanı /clients/ altında ama sayfanın varlıkları KÖKTE (/css/, /js/,
     * /vendor/). Taban /clients/ yapılsaydı vekil her isteğin başına onu
     * ekler ve varlıklar /clients/vendor/... diye istenip 404 dönerdi.
     * Taban birebir, iniş ayrı.
     */
    public function inisYolu(): string
    {
        return match ($this) {
            self::DirectAdmin => '/clients/',

            /*
             * QTS girişten sonra kendi masaüstüne gidiyor; kök yalnızca giriş
             * ekranını çiziyor ve oturum zaten açık olduğu için orada
             * beklemenin anlamı yok (cihazın kendi betiği de tam olarak buraya
             * yolluyor: main.html).
             */
            self::Qnap => '/cgi-bin/main.html',

            /*
             * Spider girişten sonra kendi ana sayfasına yolluyor (cihazın
             * 302'si birebir buraya işaret ediyor); kökte giriş formu var ve
             * oturum zaten açıkken orada beklemenin anlamı yok.
             */
            self::Spider => '/home.asp',

            /*
             * RSPAMD KÖKTE AÇILIR — ve bu, arayüzün kendi kodunun dayattığı
             * bir şart. js/main.js adresi şöyle kuruyor:
             *
             *     location.hostname + (port) +
             *     (location.pathname !== "/" ? location.pathname : "")
             *
             * Yani sayfa kökte DEĞİLSE taban o yola kilitleniyor ve API
             * çağrıları oraya ekleniyor: sayfa /cihaz-oturum/sayfa adresinde
             * açıldığında arayüz "/cihaz-oturum/sayfastat" ister ve 404 alır;
             * ekranda "Cannot get server status: HTTP 404" yazar.
             *
             * Kökte açılınca istek "/stat" oluyor ve vekil onu cihazın
             * /rspamd/ tabanına ekliyor (bkz. cihazYolu).
             */
            self::Rspamd => '/',

            /*
             * mailcow girişten sonra kendi panosuna gidiyor (302 ile birebir
             * buraya işaret ediyor); kökte son kullanıcı arayüzü var ve
             * yönetici oturumu açıkken orada beklemenin anlamı yok.
             */
            self::Mailcow => '/admin/dashboard',

            default => $this->cihazYolu(),
        };
    }

    /**
     * Arayüzün KÖKTEN istediği yollar.
     *
     * Vekillenen sayfa alt bir dizinde duruyor ama bu istekler köke gidiyor
     * ve adresleri arayüzün içinde SABİT. Değiştiremeyiz, yalnızca
     * karşılayabiliriz; nginx yapılandırmasındaki konumlar bunlarla birebir
     * aynı olmalı.
     *
     * @return list<string>
     */
    public function kokYollari(): array
    {
        return match ($this) {
            self::Esxi => ['/sdk', '/ui/'],
            self::Vami => ['/rest/'],
            /*
             * vSphere Client kökü baştan aşağı kullanıyor. /apigw ve /cp/
             * SONRADAN eklendi: yeni sihirbazlar (ör. "New Virtual Machine" →
             * Customize hardware) verilerini API geçidinden çekiyor ve o
             * adresler cihazın KÖKÜNDE duruyor. Taban dizinine çevrildiklerinde
             * /ui/apigw oluyor ve vCenter 401 döndürür — ekranda "Error
             * Loading Data … {"status":401}" (erişim günlüğünde POST /apigw
             * ve POST /cp/graphql 401).
             */
            /*
             * /webconsole-ticket/ SONRADAN eklendi ve sebebi VM KONSOLU.
             *
             * vCenter 8 konsolu kendi üzerinden geçiriyor: arayüz önce
             * /ui/webconsole-ticket'tan bir ROTA alıyor ("routeCreated"), sonra
             * konsolun canlı bağlantısını o rotaya kuruyor — ve rota cihazın
             * KÖKÜNDE duruyor.
             *
             * Taban dizinine çevrildiğinde /ui/webconsole-ticket/<rota> oluyor;
             * orası arayüzün bilet ucu ve fazladan yolu yok sayıp şunu
             * döndürüyor (130 bayt — kullanıcının tarayıcısının aldığı cevapla
             * birebir aynı):
             *
             *     {"ticketUrl":"null","routeCreated":false,"isError":true,
             *      "errorMessage":"An error occurred while retrieving the web
             *      console URL."}
             *
             * Konsol bunun üzerine bağlantıyı HİÇ denemiyor: erişim kaydında
             * tek satır bile yok, ekranda yalnızca "Couldn't establish a
             * connection to the VM web console." yazıyor.
             */
            self::Vcenter => ['/sdk', '/ui/', '/websso/', '/rest/', '/apigw', '/cp/', '/webconsole-ticket/'],

            /*
             * DIRECTADMIN'DE KÖK YOLU GEREKMİYOR: bu arayüz oturum çerezli
             * dinleyiciden (9080/9443) açılıyor ve orada arayüz zaten KÖKTE
             * duruyor — portalın /clients/, /css/, /js/, /vendor/ istekleri
             * doğrudan vekilden geçiyor.
             *
             * Panelin ana kökenine bu yollar EKLENMEZ: gereksizdir ve panelin
             * kendi adresinde dört yeni yol açardı.
             */
            default => [],
        };
    }

    /** Açılış betiği. */
    public function acilisGorunumu(): ?string
    {
        return match ($this) {
            self::Esxi => 'credentials.arayuz.esxi',
            self::Vami => 'credentials.arayuz.vami',

            /*
             * iLO BURAYA YAZILMAZ — yazılırsa arayüz bozulur.
             *
             * Bu liste yalnızca "açılış betiği" demek değil; dolu olması
             * arayüzün SAYFASINI PANELİN VERMESİ demek (bkz. adres()). O kipte
             * sayfa /cihaz-oturum/sayfa adresinde duruyor ve iLO'nun GÖRELİ
             * istekleri panele düşüyor: "html/application.html" →
             * /cihaz-oturum/html/application.html → panelin 404 sayfası
             * (arayüz hiç açılmaz).
             *
             * iLO'nun konsol düğmesine gereken betik ortak açılış betiğine
             * eklendi; o, kipi değiştirmeden her cihaz sayfasına iniyor
             * (bkz. ayrilis.blade.php).
             */

            /*
             * RSPAMD'DA BETİK YOK — ve gerekmiyor.
             *
             * Arayüz açılışta "/stat" soruyor; yanıt 200 ise giriş kutusunu
             * HİÇ göstermeden panoya geçiyor (ui.connect, js/app/rspamd.js).
             * Parolayı vekil başlığa koyduğu için o istek zaten 200 dönüyor.
             *
             * Betik eklemek üstelik zararlı: betikli açılış sayfayı
             * /cihaz-oturum/sayfa adresine taşıyor, arayüz de tabanını
             * location.pathname'den kurduğu için bütün API çağrıları oraya
             * yapışıp 404 olur (bkz. inisYolu).
             */

            /*
             * Ötekilerde açılış betiği YOK: oturumu sunucu açıyor ve çerezi
             * vekil enjekte ediyor. Tarayıcıya ne parola ne de cihazın
             * çerezi iniyor.
             */
            default => null,
        };
    }

    /**
     * Sır cihaza HANGİ BAŞLIKLA gider?
     *
     * Ötekilerde oturum bir ÇEREZ: panel cihazda giriş yapıyor, dönen çerezi
     * saklıyor, vekil her isteğe onu ekliyor. Rspamd'ın oturumu yok — hesap
     * kavramı bile yok, tek bir parola var ve arayüz onu her isteğin
     * `Password` başlığında gönderiyor.
     *
     * mailcow üzerindeki Rspamd'da:
     *
     *     GET /rspamd/auth                 → 401 {"error":"Unauthorized"}
     *     GET /rspamd/auth  Password: …    → 200 {"auth":"ok",…}
     *
     * Bu yüzden vekil o başlığı kendisi koyar ve parola tarayıcıya hiç inmez.
     * null dönen türlerde böyle bir başlık yoktur.
     */
    public function gizliBasligi(): ?string
    {
        return match ($this) {
            self::Rspamd => 'Password',
            default => null,
        };
    }

    /**
     * Bu arayüz KULLANICI ADI ister mi?
     *
     * Neredeyse hepsi ister: kimlik "kullanıcı + parola". Rspamd'ın hesap
     * kavramı YOK — tek bir parola var ve cihaz kullanıcı adını hiç görmüyor.
     * Kayıtta kullanıcı adı şart koşulsaydı, kasaya hiçbir karşılığı olmayan
     * bir değer yazmak gerekirdi; yazılmadığında ise arayüz sessizce
     * açılmazdı (düğme hiç çıkmaz, sebebi de yazmaz).
     */
    public function kullaniciAdiGerekir(): bool
    {
        return $this !== self::Rspamd;
    }

    /** vSphere ailesinden mi (ESXi, vCenter, VAMI)? */
    public function vsphereMi(): bool
    {
        return in_array($this, [self::Esxi, self::Vcenter, self::Vami], true);
    }

    /** Kayıtta port yazmıyorsa bu arayüzün olağan portu. */
    public function varsayilanPort(): int
    {
        return match ($this) {
            self::Vami => 5480,
            self::Plesk => 8443,
            default => 443,
        };
    }

    /**
     * Oturumu SUNUCU mu açıyor?
     *
     * Açıyorsa parola tarayıcıya hiç uğramaz: panel cihazda giriş yapar,
     * dönen çerezi kendinde tutar ve vekil her isteğe onu ekler. Tarayıcı ne
     * parolayı ne de cihazın oturum çerezini görür.
     *
     * ESXi ve VAMI'de bu mümkün değil: ESXi oturumu istemcinin ürettiği CSRF
     * jetonuna bağlıyor, VAMI jetonu localStorage'da arıyor. Onlarda girişi
     * arayüzün kendi betiği yapıyor.
     */
    public function sunucudaGiris(): bool
    {
        return match ($this) {
            self::Plesk, self::Ilo, self::Vcenter, self::DirectAdmin, self::Qnap, self::Spider, self::Mailcow => true,
            default => false,
        };
    }

    /**
     * Tarayıcıdan gelen çerezlerden hangileri cihaza İLETİLİR?
     *
     * Sunucuda giriş yapılan arayüzlerde HİÇBİRİ: cihazın oturumu bizde
     * duruyor. Ötekilerde cihazın kendi çerezleri iletilir — ama panelin ve
     * vekilin çerezleri ASLA; onların cihazda işi yok.
     *
     * @return list<string>
     */
    public function iletilecekCerezler(): array
    {
        return match ($this) {
            self::Esxi => ['vmware_soap_session', 'vmware_client', 'vmware_esx_host'],
            self::Vami => [],
            default => [],
        };
    }

    /**
     * Arayüzün KENDİ BETİĞİNİN okuması gereken çerezler.
     *
     * Normalde cihazın oturum çerezi sunucuda kalır ve vekil onu her isteğe
     * kendisi ekler; tarayıcı çerezi hiç görmez. Ama bazı arayüzler oturumun
     * varlığını sunucuya sormuyor, tarayıcıda yokluyor:
     *
     * iLO 4'te index.html'in son satırı aynen şu: `if (iLO.getCookie("sessionKey") || iLOGlobal.cache.session_key)
     * ... else showLogin("fresh")`. Çerez tarayıcıda olmadığı için sayfa,
     * vekil isteklere çerezi eklese bile, giriş ekranını çizer (boş ekran
     * olarak görünür).
     *
     * PAROLA YİNE GİTMEZ. Verilen şey cihazın kendi oturum çerezi; kullanıcı
     * cihaza doğrudan bağlansaydı tarayıcısında zaten o duracaktı. Kazanılan
     * şey parolanın hiç uğramaması ve oturumun panelce sonlandırılabilmesi.
     *
     * LİSTE AÇIK YAZILIR, "hepsini ver" denmez: cihaz istediği adı yollayabilir
     * ve tanımadığımız bir çerezi tarayıcıya açmak, cihazın panel kökeninde
     * çerez yazmasına izin vermek olurdu.
     *
     * @return array<int, string>
     */
    public function tarayiciCerezleri(): array
    {
        return match ($this) {
            self::Ilo => ['sessionKey'],

            /*
             * QTS de oturumu TARAYICIDA yokluyor: masaüstü betiği NAS_SID'i
             * `document.cookie`den okuyup API çağrılarını ona göre kuruyor ve
             * çıkışta üçünü birden siliyor (cihazın kendi betiğinde okundu,
             * qos-core-login.js: delCookie("NAS_SID"), delCookie("home"),
             * delCookie("NAS_USER")). Çerez yalnızca sunucuda kalsaydı sayfa,
             * vekil isteklere onu eklese bile giriş ekranına dönerdi — iLO'daki
             * durumun aynısı.
             *
             * PAROLA YİNE İNMİYOR: tarayıcıya giden şey oturum kimliği ve
             * oturum panelden kapatılınca cihazda da kapanıyor.
             */
            self::Qnap => ['NAS_SID', 'NAS_USER', 'home'],

            default => [],
        };
    }

    /**
     * Şifrelenmeyecek çerez adları (Laravel'in çerez şifrelemesi için).
     *
     * Bunlar CİHAZIN çerezleri, panelin değil: şifrelenirlerse ne cihazın
     * betiği kendi değerini tanır ne de biz geri okuyabiliriz.
     *
     * @return array<int, string>
     */
    public static function sifrelenmeyenCerezler(): array
    {
        $adlar = [ArayuzOturumu::KONAK_COOKIE];

        foreach (self::cases() as $tur) {
            $adlar = array_merge($adlar, $tur->tarayiciCerezleri());
        }

        return array_values(array_unique($adlar));
    }

    public function etiket(): string
    {
        return match ($this) {
            self::Esxi => 'ESXi arayüzü',
            self::Vami => 'Appliance yönetim arayüzü',
            self::Plesk => 'Plesk paneli',
            self::Ilo => 'iLO arayüzü',
            self::Vcenter => 'vSphere Client',
            self::DirectAdmin => 'DirectAdmin hesap portalı',
            self::Qnap => 'QNAP yönetim arayüzü',
            self::Spider => 'Lantronix Spider arayüzü',
            self::Rspamd => 'Rspamd denetim arayüzü',
            self::Mailcow => 'mailcow yönetim arayüzü',
        };
    }

    /**
     * ADRESTE OTURUM ÖNEKİ KULLANILIR MI?
     *
     * Önek (/cihaz-oturum/<oturum>/…) sekmeleri ayırmanın tek güvenilir
     * yolu: çerez tarayıcıya ait, adres sekmeye. İki Plesk'i ancak bu ayırır.
     *
     * RSPAMD DIŞARIDA — arayüzün kendi kodu buna izin vermiyor. js/main.js
     * tabanını `location.pathname`den kuruyor; sayfa önek altında açılınca
     * bütün API çağrıları o yola yapışıyor ve 404 dönüyor (bkz. inisYolu). Onda oturum yine çerezle taşınır.
     */
    public function yolOnekiKullanir(): bool
    {
        /*
         * YOL ÖNEKİ ARTIK KULLANILMIYOR — izolasyonu PORT sağlıyor.
         *
         * Önek, cihazların MUTLAK adreslerinde (Plesk'in
         * "/admin/domain/list?context=domains" gibi) düşüyordu; sayfa geçişi
         * önekin dışına çıkıyor ve oturum yine çereze kalıyordu. Port böyle bir
         * kaçış bırakmıyor: mutlak adres de aynı portta kalır.
         *
         * İşlev yerinde duruyor çünkü havuz boşsa (ya da ters vekilde aralık
         * açılmamışsa) arayüz yine ortak portta açılır ve orada eski davranış
         * geçerlidir.
         */
        return false;
    }

    /**
     * Önekten SONRA gelen iniş yolu.
     *
     * Önek altındaki adres cihazın TABAN dizinine göre yazılır; tabanı vekil
     * ekliyor (X-Eys-Taban). vCenter'da taban zaten "/ui/" olduğu için önekten
     * sonrası boş kalır; QNAP'ta "cgi-bin/main.html" olur.
     *
     * Aksi hâlde taban iki kez eklenirdi: /cihaz-oturum/<oturum>/ui/ adresi
     * cihazda /ui/ui/ diye aranırdı.
     */
    public function onekInisYolu(): string
    {
        $taban = $this->cihazYolu();
        $inis = $this->inisYolu();

        return ltrim(str_starts_with($inis, $taban) ? substr($inis, strlen($taban)) : $inis, '/');
    }

    /**
     * YALNIZCA BU ARAYÜZDE BULUNAN YOLLAR — çerez yanılırsa karar bunlarındır.
     *
     * Vekil kökeninde oturumu çerez taşıyor ve çerez sekmeye değil TARAYICIYA
     * ait: kullanıcı iki arayüzü iki sekmede açtığında son açılan hepsini
     * sahipleniyor, eski sekmenin istekleri yanlış cihaza gidiyor. Belirtisi
     * "bağlantı hatası" gibi görünmüyor — cihaz yolu tanımadığı için kendi
     * diliyle 404/400 diyor ve sebep hiç akla gelmiyor.
     *
     * Örnekler:
     *   - vSphere sekmesinin `/ui/usersession/sessionPing` isteği iLO'ya
     *     giderse 404 (0 bayt) alır; aynı istek vCenter'da 200 döner. Arayüz
     *     oturumu ölmüş sayıp görev listesini boş bırakır.
     *   - iLO konsolunun `/wss/ircport` isteği yanlış cihaza giderse 400 alır;
     *     iLO'nun kendisi aynı el sıkışmaya her denemede 101 veriyor.
     *
     * LİSTEYE YOL EKLEMEK BİR İDDİADIR: "bu yol başka hiçbir arayüzde yok".
     * Bu yüzden kısa tutuldu ve her biri cihazda ölçüldü.
     *
     * @return list<string>
     */
    public function sahiplendigiYollar(): array
    {
        return match ($this) {
            /*
             * BU LİSTE kokYollari() DEĞİLDİR — öyle yazılırsa Plesk bozulur.
             *
             * İki listenin soruları ayrı: kokYollari() "bu yola taban dizini
             * EKLENMESİN" der, burası "bu yol yalnızca bu arayüzde VAR" der.
             * `/cp/` ikisinin ayrıldığı yer: vSphere'in API geçidi orada
             * (`/cp/graphql`) ve taban eklenmemeli; ama Plesk'in kendi paneli
             * de `/cp/` altında duruyor.
             *
             * İki cihazda:
             *   /cp/home → Plesk 303  |  vCenter 404 (0 bayt)
             *
             * Sahiplik `/cp/`ye verilirse kullanıcının Plesk isteği açık
             * vCenter oturumuna gider ve panel açılmaz — ekranda yalnızca
             * cihazın 404'ü görünür, sebebi hiç akla gelmez.
             *
             * `/cp/graphql` DE DIŞARIDA, çünkü o ucu PLESK DE SUNUYOR:
             *
             *     POST /cp/graphql → Plesk 303 | vCenter 400
             *     GET  /cp/graphql → Plesk 303 | vCenter 404
             *
             * İki cihazda birden var olan bir yol, hiçbirinin "yalnızca bende"
             * diyebileceği bir yol değildir. Sahiplendirilirse Plesk'in kendi
             * GraphQL çağrıları açık vCenter oturumuna gidip 400 alır ve panel
             * kendini yenileyip durur.
             *
             * `/rest/` de bilerek dışarıda: VAMI'nin kök yolu ama "rest" öneki
             * bir arayüze özgü sayılamayacak kadar yaygın.
             *
             * KALAN LİSTE CİHAZLARA KARŞI TARANDI (vCenter, Plesk, iLO, QNAP):
             * her yol yalnızca bir ailede cevap veriyor.
             */
            self::Vcenter, self::Esxi, self::Vami => [
                '/sdk', '/ui/', '/websso/', '/apigw', '/webconsole-ticket/',
            ],

            /*
             * iLO 4: konsolun kendi sayfası, websocket ucu ve veri uçları.
             * `/irc.html` ("Integrated Remote Console") cihazın kökünde durur.
             */
            self::Ilo => ['/irc.html', '/wss/', '/json/'],

            /* QTS her şeyi buradan konuşuyor (giriş ucu dahil). */
            self::Qnap => ['/cgi-bin/'],

            default => [],
        };
    }

    /**
     * Bu yolu sahiplenen arayüzler; sahibi yoksa boş.
     *
     * vSphere ailesinde üç tür tek cihaz gibi davranıyor: yolu hangisi
     * sahiplenirse sahiplensin, oturum üçünden biri olabilir.
     *
     * @return list<self>
     */
    public static function yolunSahipleri(string $yol): array
    {
        foreach (self::cases() as $tur) {
            foreach ($tur->sahiplendigiYollar() as $onek) {
                if (str_starts_with($yol, $onek)) {
                    return $tur->vsphereMi()
                        ? [self::Vcenter, self::Esxi, self::Vami]
                        : [$tur];
                }
            }
        }

        return [];
    }
}
