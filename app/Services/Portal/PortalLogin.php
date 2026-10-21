<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\DeviceInterface\InterfaceType;

/**
 * Fırlatma sayfasının göndereceği giriş isteği.
 *
 * Tarayıcı, başka bir kökendeki sayfanın alanlarını dolduramaz ama o kökene
 * FORM GÖNDEREBİLİR. Bu yüzden portal oturumu bir formdur — ama her panel
 * aynı biçimi kabul etmiyor:
 *
 *  - Klasik paneller (Plesk gibi) alan=değer çiftleri bekler.
 *  - iLO 4 arayüzü JSON bekler; alan=değer gönderildiğinde 400 döner.
 *  - ESXi arayüzü HİÇBİR biçimde formla açılamaz; sebebi aşağıda.
 *  - vCenter arayüzü SAML ile giriyor; kullanıcı adıyla parola kimlik
 *    sağlayıcıya tek bir alanda, base64 olarak gidiyor.
 *
 * JSON ve SOAP gövdeleri için formun `text/plain` kipi kullanılır: o kipte
 * tarayıcı gövdeye tam olarak "ad=değer" yazar, kaçış yapmaz. Alan adını
 * gövdenin başı, değerini sonu yaparsak aradaki eşittir işareti zararsız bir
 * yere düşer ve ortaya geçerli bir gövde çıkar.
 *
 * Parola yine yalnızca bu tek seferlik sayfada durur ve kullanıcının ekranına
 * hiç uğramaz.
 */
final class PortalLogin
{
    /**
     * @param  array<string, string>  $fields  ad => değer
     * @param  ?string  $landing  Giriş sonrası gidilecek adres; klasik
     *                            panellerde null (panel zaten kendi ekranına
     *                            yönlendirir).
     */
    /**
     * @param  ?string  $onceki  Gönderimden ÖNCE pencerenin götürüleceği adres.
     *                           Cihazda duran eski oturumu kapatmak için; bazı
     *                           arayüzler yeni girişi eskisinin üstüne kabul
     *                           etmiyor.
     */
    private function __construct(
        public readonly string $action,
        public readonly array $fields,
        public readonly ?string $enctype = null,
        public readonly ?string $landing = null,
        public readonly ?string $onceki = null,
    ) {}

    /**
     * Kaydın hangi giriş biçimine ait olduğu.
     *
     * ESXi ve vCenter kayıtları ERİŞİM TÜRÜNDEN tanınır; iLO ise işletim
     * sistemi alanından (o kayıtlarda erişim türü "ilo" da olabiliyor,
     * "panel" de). Tek karar noktası: aynı soruyu iki yerde sormak, birini
     * güncelleyip diğerini unutmanın yoluydu.
     */
    private static function aile(Credential $credential): string
    {
        return match (true) {
            $credential->access_type === AccessType::Esxi => 'esxi',
            $credential->access_type === AccessType::Vcenter => 'vcenter',
            $credential->operating_system === OperatingSystem::Bmc => 'ilo',
            default => 'klasik',
        };
    }

    /**
     * Portal oturumu KURULABİLİR mi?
     *
     * UCUZ olmak zorunda: liste çizilirken her kayıt için sorulur. Ağa
     * çıkılmaz, yalnızca kayıtta gereken alanlar var mı diye bakılır.
     * vCenter'ın kimlik sağlayıcı adresi ancak fırlatma anında alınır.
     */
    public static function hazirMi(Credential $credential): bool
    {
        if (! $credential->has_password || blank($credential->username)) {
            return false;
        }

        /*
         * ARAYÜZÜ VEKİLLENEN KAYIT BURAYA GİRMEZ.
         *
         * İkisi de "oturum aç" diyor ama biri parolayı tarayıcıya veriyor,
         * öteki vermiyor. İki mekanizma aynı kaydı sahiplenebilseydi hangisinin
         * çalıştığı ekrandaki dalların SIRASINA kalırdı: Plesk, iLO ve vCenter
         * vekile hiç uğramadan eski yoldan açılabilirdi. Karar tek yerde ve
         * vekilden yana.
         */
        if (InterfaceType::icin($credential) !== null) {
            return false;
        }

        return match (self::aile($credential)) {
            /* ESXi BURADA YOK — bilerek; gerekçe aşağıdaki nottadır. */
            'vcenter', 'ilo' => filled($credential->host) || filled($credential->url),
            default => filled($credential->login_url)
                && filled($credential->login_username_field)
                && filled($credential->login_password_field),
        };
    }

    /**
     * Girişten sonra gidilecek adres.
     *
     * AĞA ÇIKMADAN bilinir: liste çizilirken her kayıt için sayfaya yazılıyor
     * ve orada ağ erişimi olamaz. Tanım TEK yerdedir; kurucular da buradan
     * okur, liste de. İki yerde yazılsaydı biri değişip diğeri kalırdı.
     */
    /**
     * Gönderimden ÖNCE gidilecek adres — ağa çıkmadan bilinir.
     *
     * Yalnızca vCenter'da var: açık bir oturum dururken yeni giriş gönderimini
     * reddediyor, önce çıkış gerekiyor.
     */
    public static function oncekiAdres(Credential $credential): ?string
    {
        /*
         * KAPALI — tarayıcıda ÇALIŞMIYOR.
         *
         * İhtiyaç gerçek: vCenter açık bir oturum dururken yeni SAML belgesini
         * reddedip 401 veriyor ve ikinci açılış böyle düşebiliyor. Cihazın
         * çıkış ucu o durumu temizliyor; SUNUCUDA adım adım (vCenter 8):
         *
         *     ilk giriş                   -> SP 302
         *     aynı çerezle ikinci giriş   -> SP 401
         *     /ui/logout                  -> 200
         *     çıkıştan sonra ikinci giriş -> SP 302
         *
         * Ama tarayıcıda pencereyi önce çıkış adresine götürüp sonra gönderim
         * yapmak İŞE YARAMIYOR: her iki açılış da giriş ekranında kalıyor —
         * yani yalnız ikinci açılışı değil, çalışan ilkini de bozuyor.
         * Sebebi bulunmadan açılmamalı.
         *
         * Şimdilik çare kullanıcıda: arayüzden çıkış yapmak ya da cihazın
         * çerezlerini temizlemek. Kalıcı çözüm, oturumu panelin tuttuğu
         * vekil yolu (bkz. InterfaceType) — orada ikinci açılış diye bir sorun
         * yok, yeni oturum açılır.
         */
        return null;
    }

    /**
     * İniş adresine geçmeden önce BEKLENECEK süre (ms).
     *
     * vCenter'ın giriş yanıtı bir sayfa değil, KENDİ KENDİNİ YOLLAYAN bir
     * SAML formudur: tarayıcı onu gönderene kadar oturum çerezi yazılmaz.
     * Devir hemen yapılırsa bu gönderim yarıda kalır ve arayüz oturumsuz
     * açılır.
     *
     * vCenter 8'de gönderimden arayüze düşmek birkaç saniye sürer; ikinci
     * açılışta kimlik sağlayıcının 401'i daha erken görünür. Bekleme ikisini
     * de kapsar: ilk açılış kendi yolunu tamamlar, ikincisi hata sayfasından
     * arayüze alınır.
     *
     * Devri olmayan ailelerde (iLO) bekleme YOK: orada çerez giriş yanıtıyla
     * birlikte yazılıyor ve beklemek yalnızca kullanıcıyı oyalardı.
     */
    public static function inisGecikmesi(Credential $credential): ?int
    {
        return self::hazirMi($credential) && self::aile($credential) === 'vcenter'
            ? 3500
            : null;
    }

    public static function inisAdresi(Credential $credential): ?string
    {
        if (! self::hazirMi($credential)) {
            return null;
        }

        $taban = DeviceAddress::for($credential);

        return match (self::aile($credential)) {
            'ilo' => $taban.'/',

            /*
             * vCenter'da devir ŞART: ikinci açılışta kimlik sağlayıcı yeni
             * gönderimi reddediyor ama oturum zaten açık oluyor. Devir,
             * kullanıcıyı hata sayfasında bırakmak yerine arayüze götürüyor.
             */
            'vcenter' => $taban.'/ui/',

            /* Klasik paneller girişten sonra kendi ekranlarına yönlendirir. */
            default => null,
        };
    }

    public static function for(Credential $credential): ?self
    {
        if (! self::hazirMi($credential)) {
            return null;
        }

        return match (self::aile($credential)) {
            'vcenter' => self::vcenter($credential),
            'ilo' => self::ilo($credential),
            default => self::classic($credential),
        };
    }

    /** Alan adları kayıtta yazan klasik giriş formu. */
    private static function classic(Credential $credential): ?self
    {
        if (blank($credential->login_url)
            || blank($credential->login_username_field)
            || blank($credential->login_password_field)) {
            return null;
        }

        return new self((string) $credential->login_url, [
            (string) $credential->login_username_field => (string) $credential->username,
            (string) $credential->login_password_field => (string) $credential->password,
            ...($credential->login_extra_fields ?? []),
        ]);
    }

    /**
     * iLO 4: JSON gövde, `text/plain` kipiyle.
     *
     * Gövde tam olarak "ad=değer" olur; adın sonunu ve değerin başını öyle
     * seçiyoruz ki aradaki eşittir işareti JSON'un içinde zararsız bir alana
     * düşsün.
     */
    private static function ilo(Credential $credential): ?self
    {
        if (blank($credential->host)) {
            return null;
        }

        $bas = '{"method":"login","user_login":'.self::json((string) $credential->username)
            .',"password":'.self::json((string) $credential->password).',"eys":"';

        $taban = DeviceAddress::for($credential);

        /*
         * iLO giriş isteğine SAYFA DEĞİL VERİ döndürür: oturum çerezi yazılır
         * ama tarayıcı ham JSON'un üstünde kalır. Kullanıcıyı orada bırakmamak
         * için arayüzün adresi de taşınır (bkz. resources/js/components/portal.js).
         */
        return new self(
            $taban.'/json/login_session',
            [$bas => '"}'],
            'text/plain',
            self::inisAdresi($credential),
        );
    }

    /**
     * ESXİ NEDEN YOK.
     *
     * ESXi Host Client'ın giriş ekranı bir form değil; oturumu SOAP `Login`
     * çağrısı açıyor ve yanıt `vmware_soap_session` çerezini yazıyor. Buraya
     * kadarı formla YAPILABİLİYOR: /sdk gövdeyi `text/plain` başlığıyla kabul
     * ediyor ve `SOAPAction` istemiyor (ESXi 8'de 200 ve çerez geliyor;
     * çerezin tarayıcıda da yazıldığı Chromium ile doğrulandı).
     *
     * Ama arayüz yine giriş ekranı gösteriyor. Sebep CSRF korumasıdır: Host
     * Client her SOAP çağrısına KENDİ ürettiği `VMware-CSRF-Token` başlığını
     * koyuyor (main.js: `StorageManager.get('csrf_token', Utils.uniqueId())`)
     * ve ESXi oturumu GİRİŞTEKİ jetona bağlıyor. Dört durum:
     *
     *     giriş jetonsuz  + çağrı jetonsuz  -> 200
     *     giriş jetonsuz  + çağrı jetonlu   -> "The session is not authenticated"
     *     giriş jeton ABC + çağrı jeton ABC -> 200
     *     giriş jeton ABC + çağrı jeton XYZ -> "The session is not authenticated"
     *
     * Form isteğe BAŞLIK EKLEYEMEZ, başka bir kökenin localStorage'ına da
     * yazılamaz. Yani panelin açtığı oturumun jetonu ile arayüzün kullanacağı
     * jeton hiçbir zaman tutmaz. Bu, ESXi'nin tam da bizim yaptığımız türden
     * çapraz köken form gönderimine karşı koyduğu korumadır; aşılmaya
     * çalışılmamalıdır.
     *
     * Kalıcı çözüm arayüzü panelin KENDİ KÖKENİNDEN vekillemektir: o zaman
     * hem çerez hem localStorage bizim kökenimizde olur ve Host Client'ın
     * hazır `x-vsphere-proxy` kipi kullanılabilir. Ayrı bir iştir.
     *
     * O zamana kadar ESXi kayıtlarında ekran "Arayüzü aç" diyor ve parola
     * kopyalanıyor — çalışmayan bir düğme göstermekten dürüsttür.
     */
    /**
     * vSphere Client: SAML kimlik sağlayıcısına tek alanlık giriş.
     *
     * Arayüz kendi başına giriş almıyor; tarayıcıyı vCenter'ın kimlik
     * sağlayıcısına (websso) yolluyor ve oradan SAML yanıtıyla dönüyor.
     * Kimlik sağlayıcının giriş ekranı JavaScript ile TEK bir alan
     * gönderiyor: `CastleAuthorization=Basic <base64(kullanıcı:parola)>`,
     * sıradan `application/x-www-form-urlencoded` gövdesiyle. Yani formdan
     * gönderilebilir.
     *
     * Yanıt, SAML belgesini vCenter'a geri gönderen KENDİ KENDİNİ YOLLAYAN
     * bir HTML formudur; tarayıcı oradan doğrudan arayüze düşer. Bu yüzden
     * ayrıca bir "iniş adresi" gerekmez.
     *
     * vCenter 8'de sıra ÇEREZSİZ çalışıyor
     * — kimlik sağlayıcı isteği imzalı SAMLRequest'ten doğruluyor ve sunucu
     * tarafında alınmış bir adres, tarayıcının hiç çerezi olmadan da kabul
     * ediliyor (SP 302 ile /ui/ye yolluyor ve oturum çerezini orada yazıyor).
     * Adresi bu yüzden panel önceden alabiliyor.
     */
    private static function vcenter(Credential $credential): ?self
    {
        $adres = VcenterSsoAddress::al($credential);

        if ($adres === null) {
            return null;
        }

        /*
         * GÖNDERİMDEN SONRA ARAYÜZE DEVREDİLİR.
         *
         * İlk giriş tarayıcıda vCenter'ın oturum çerezini bırakıyor; ikinci
         * açılışta kimlik sağlayıcı yeni gönderimi kabul etmeyip
         * "[401] This request requires HTTP authentication" diyor ve kullanıcı
         * o hata sayfasında kalıyor (gizli pencerede ilk açılış çalışıyor,
         * aynısında ikincisi düşüyor).
         *
         * Oysa o anda oturum ZATEN AÇIK. Devir ikisini de kurtarıyor: ilk
         * açılışta giriş yeni yapılmış olur, ikincisinde var olan oturumla
         * arayüze düşülür. Hata sayfası hiç görünmez.
         */
        return new self($adres, [
            'CastleAuthorization' => 'Basic '.base64_encode(
                $credential->username.':'.$credential->password
            ),
        ], landing: DeviceAddress::for($credential).'/ui/');
    }

    /** JSON dizesi — tırnak ve ters bölü içeren parolalar gövdeyi bozmasın. */
    private static function json(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
