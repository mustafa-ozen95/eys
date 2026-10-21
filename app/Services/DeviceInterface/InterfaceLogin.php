<?php

declare(strict_types=1);

namespace App\Services\DeviceInterface;

use App\Models\Credential;
use App\Services\Portal\VcenterSsoAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cihazda oturumu SUNUCUDA açar ve çerezini döndürür.
 *
 * Kazanç şu: parola kullanıcının tarayıcısına HİÇ uğramıyor. Panel cihazda
 * giriş yapıyor, dönen oturum çerezini kendinde tutuyor ve vekil her isteğe
 * onu ekliyor. Tarayıcı ne parolayı ne de cihazın çerezini görüyor.
 *
 * ESXi ve VAMI burada YOK, olamaz da: ESXi oturumu istemcinin ürettiği CSRF
 * jetonuna bağlıyor, VAMI jetonu localStorage'da arıyor (bkz. InterfaceType).
 *
 * Cihaz sertifikaları kendi imzalarıdır ve doğrulama kapalıdır. Parola
 * buradan geçiyor ama ağ iç ağdır; alternatifi parolayı tarayıcıya vermekti
 * ve bu ondan iyisidir.
 */
final class InterfaceLogin
{
    /** Cihazdan gelen son yanıt; giriş tutmazsa sebebi bundan okunur. */
    private ?Response $sonYanit = null;

    /**
     * @return string Cihaza iletilecek "Cookie" başlığının değeri.
     */
    public function ac(InterfaceType $tur, Credential $credential, string $hedef): string
    {
        $taban = 'https://'.$hedef;

        $cerez = match ($tur) {
            InterfaceType::Plesk => $this->plesk($credential, $taban),
            InterfaceType::Ilo => $this->ilo($credential, $taban),
            InterfaceType::Vcenter => $this->vcenter($credential, $taban),
            InterfaceType::DirectAdmin => $this->directAdmin($credential, $taban),
            InterfaceType::Qnap => $this->qnap($credential, $taban),
            InterfaceType::Spider => $this->spider($credential, $taban),
            InterfaceType::Mailcow => $this->mailcow($credential, $taban),
            default => throw new RuntimeException('Bu arayüzün oturumu sunucuda açılmıyor.'),
        };

        if ($cerez === '') {
            throw new RuntimeException($tur->etiket().': cihazda oturum açılamadı'.$this->neden().'.');
        }

        return $cerez;
    }

    /**
     * CİHAZ NEDEN KABUL ETMEDİ — durum kodundan okunabilen kadarı.
     *
     * Cihazın gövdesi YAZILMAZ: içinde ne olduğu cihazdan cihaza değişiyor ve
     * bir hata sayfasının panelin ekranına dökülmesi hem okunmaz hem de
     * gereksiz bilgi sızdırır. Durum kodu ise sebebi ayırmaya yetiyor ve
     * kullanıcıya ne yapacağını söyler: 401 "parola eskimiş olabilir", 404
     * "adres yanlış", 5xx "cihazın kendi arızası".
     */
    private function neden(): string
    {
        $yanit = $this->sonYanit;

        if ($yanit === null) {
            return '';
        }

        $durum = $yanit->status();

        return ' — '.match (true) {
            $durum === 401 => 'cihaz kimliği reddetti (401): kasadaki parola eskimiş ya da hesap kilitli olabilir',
            $durum === 403 => 'cihaz erişimi reddetti (403): hesabın bu arayüze yetkisi olmayabilir',
            $durum === 404 => 'giriş adresi bulunamadı (404): kayıttaki adres ya da port değişmiş olabilir',
            $durum === 429 => 'cihaz çok fazla deneme gördü (429): bir süre beklemek gerekiyor',
            $durum >= 500 => 'cihaz hata verdi ('.$durum.')',
            default => 'cihaz isteği kabul etti ama oturum çerezi vermedi ('.$durum.'): kimlik büyük olasılıkla geçersiz',
        };
    }

    /**
     * Plesk: klasik form girişi.
     *
     * Plesk 303 dönüyor ve PLESKSESSID çerezini yazıyor. Yönlendirme İZLENMEZ — gereken tek şey çerez.
     */
    private function plesk(Credential $credential, string $taban): string
    {
        return $this->cerezi($this->istek()->asForm()->post($taban.'/login_up.php', [
            'login_name' => (string) $credential->username,
            'passwd' => (string) $credential->password,
        ]));
    }

    /**
     * DirectAdmin hesap portalı (/clients): iki adım ve bir REFERER.
     *
     * Portal, giriş isteğini kendi sayfasından gelmiş saymak istiyor ve bunu
     * `Referer` başlığıyla denetliyor:
     *
     *     Referer: portalın kendi sayfası        → gövde "true"
     *     Referer yok ya da yabancı köken        → "Referer check has failed"
     *     ajax_login'siz düz gönderim            → giriş olmuyor
     *
     * Başlığı tarayıcı yazdığı için bu giriş ancak SUNUCUDA yapılabiliyor;
     * arayüzün vekillenmesinin tek sebebi de bu.
     *
     * İKİ ADIM: önce sayfa alınıp oturum çerezi kapılıyor, sonra giriş o
     * çerezle gönderiliyor — çerezsiz gönderimde portal yine reddediyor.
     */
    private function directAdmin(Credential $credential, string $taban): string
    {
        $cerez = $this->cerezi($this->istek()->get($taban.'/clients/index.php'));

        $yanit = $this->istek()
            ->withHeaders([
                'Referer' => $taban.'/clients/index.php',
                'Cookie' => $cerez,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->asForm()
            ->post($taban.'/clients/login.php', [
                'ajax_login' => '1',
                'uid' => (string) $credential->username,
                'password' => (string) $credential->password,
            ]);

        /*
         * PORTAL HATAYI DA 200 İLE DÖNÜYOR: yanlış parolada gövdede hata
         * metni, doğru girişte tek kelime "true". Durum koduna bakmak
         * başarısız bir girişi başarılı sayardı.
         */
        if (trim($yanit->body()) !== 'true') {
            return '';
        }

        return $this->birlestir($cerez, $this->cerezi($yanit));
    }

    /**
     * QNAP (QTS): giriş ucu ÇEREZ DEĞİL XML döndürüyor.
     *
     * `authLogin.cgi` 200 dönüyor, gövdede
     * `<authPassed>1</authPassed>` ve `<authSid>` var; Set-Cookie başlığı
     * YOK. Çerezi normalde arayüzün kendi betiği yazıyor — burada onu biz
     * kuruyoruz ve vekil her isteğe ekliyor.
     *
     * Parola BASE64: cihazın kendi betiği de öyle gönderiyor
     * (qos-core-login.js → `pwd = ezEncode(utf16to8(parola))`). Bu bir
     * şifreleme değil, cihazın beklediği biçim.
     */
    private function qnap(Credential $credential, string $taban): string
    {
        $yanit = $this->istek()->asForm()->post($taban.'/cgi-bin/authLogin.cgi', [
            'user' => (string) $credential->username,
            'pwd' => base64_encode((string) $credential->password),
            'serviceKey' => '1',
        ]);

        if (! $yanit->successful() || $this->xml($yanit->body(), 'authPassed') !== '1') {
            return '';
        }

        $sid = $this->xml($yanit->body(), 'authSid');

        if ($sid === '') {
            return '';
        }

        /*
         * QTS oturumu bu iki çerezle tanıyor; `home` masaüstünü açan bayrak.
         * Arayüzün kendi betiği de tam olarak bunları yazıyor.
         */
        return 'NAS_SID='.$sid.'; NAS_USER='.$credential->username.'; home=1';
    }

    /**
     * LANTRONIX SPIDER: form girişi, yanıtta oturum çerezi.
     *
     * Cihazın verdiği yanıt:
     *
     *     POST /auth.asp  login=…&password=…&action_login=Login
     *       → 302, Set-Cookie: pp_session_id=<64 onaltılık>; path=/
     *       → Location: /home.asp
     *
     * YÖNLENDİRME İZLENMEZ: çerez 302'nin başlığında geliyor ve bize gereken
     * o. Yönlendirme izlense cihazda bir sayfa daha çizilir, elimize geçen
     * şey değişmezdi.
     *
     * DURUM KODUNA DEĞİL ÇEREZE BAKILIR: yanlış parolada cihaz da 200/302
     * dönebiliyor; oturumun açıldığının tek kanıtı çerezin kendisi.
     */
    private function spider(Credential $credential, string $taban): string
    {
        $yanit = $this->istek()->asForm()->post($taban.'/auth.asp', [
            'login' => (string) $credential->username,
            'password' => (string) $credential->password,
            'action_login' => 'Login',
        ]);

        $cerez = $this->cerezi($yanit);

        if ($cerez === '') {
            throw new RuntimeException('Cihaz oturum çerezi vermedi; kullanıcı adı ya da parola kabul edilmemiş olabilir.');
        }

        return $cerez;
    }

    /**
     * MAILCOW: yönetim kökünde form girişi.
     *
     * mailcow (2026-07 sürümü):
     *
     *     GET  /admin/   → 200, Set-Cookie: MCSESSID=…
     *     POST /admin/   login_user=…&pass_user=…
     *                    → 302, Location: /admin/dashboard
     *
     * YOL ÖNEMLİ: giriş formu BULUNDUĞU SAYFAYA gönderiyor ve yönetici
     * girişini işleyen tetikleyici yalnızca /admin altında yükleniyor. Köke
     * gönderilen aynı istek 200 dönüyor, oturum açılmıyor ve günlüğe
     * "Invalid password" düşüyor — parola doğru olsa bile (ölçüldü).
     *
     * ÖNCE ÇEREZ ALINIR: mailcow oturumu GET ile başlatıyor ve POST'u o
     * oturuma bağlıyor; çerezsiz gönderim kabul edilmiyor.
     */
    private function mailcow(Credential $credential, string $taban): string
    {
        /*
         * OTURUM TARAYICININ KİMLİĞİYLE AÇILIR.
         *
         * mailcow oturumu açarken tarayıcı kimliğini saklıyor ve sonraki her
         * istekte karşılaştırıyor (inc/sessions.inc.php: SESS_REMOTE_UA).
         * Girişi panel yaptığı için orada panelin istemcisi yazılıyordu;
         * kullanıcı aynı oturumu KENDİ tarayıcısıyla sürdürünce mailcow
         * uyuşmazlığı görüp her form isteğinde
         *
         *     Form token invalid: User-Agent validation error
         *
         * diyordu. Arayüz çalışıyor görünüyordu çünkü okuma istekleri geçiyor;
         * form gönderen her işlem sessizce reddediliyordu.
         *
         * Kimlik UYDURULMAZ, kullanıcının isteğinden alınır: oturumu açan
         * istek zaten onun tarayıcısından geliyor.
         */
        $tarayici = (string) (request()->userAgent() ?? '');

        $baslik = $tarayici === '' ? [] : ['User-Agent' => $tarayici];

        $ilk = $this->istek()->withHeaders($baslik)->get($taban.'/admin/');
        $cerez = $this->cerezi($ilk);

        $yanit = $this->istek()
            ->withHeaders($baslik + ['Cookie' => $cerez])
            ->asForm()
            ->post($taban.'/admin/', [
                'login_user' => (string) $credential->username,
                'pass_user' => (string) $credential->password,
            ]);

        /*
         * BAŞARININ İŞARETİ YÖNLENDİRME. Yanlış parolada mailcow 200 dönüp
         * giriş sayfasını yeniden basıyor; durum koduna bakan kod başarısız
         * girişi başarılı sayardı.
         */
        if ($yanit->status() !== 302) {
            throw new RuntimeException('mailcow girişi kabul etmedi; kullanıcı adı ya da parola yanlış olabilir.');
        }

        return $this->birlestir($cerez, $this->cerezi($yanit));
    }

    /** XML gövdesinden tek bir alanı okur (CDATA'lı ya da düz). */
    private function xml(string $govde, string $alan): string
    {
        $kalip = '#<'.$alan.'>(?:<!\[CDATA\[(.*?)\]\]>|(.*?))</'.$alan.'>#s';

        if (preg_match($kalip, $govde, $e) !== 1) {
            return '';
        }

        return trim($e[1] !== '' ? $e[1] : ($e[2] ?? ''));
    }

    /**
     * iLO: JSON girişi.
     *
     * iLO 4 200 dönüyor; gövdede session_key, başlıkta sessionKey çerezi.
     */
    private function ilo(Credential $credential, string $taban): string
    {
        return $this->cerezi($this->istek()->post($taban.'/json/login_session', [
            'method' => 'login',
            'user_login' => (string) $credential->username,
            'password' => (string) $credential->password,
        ]));
    }

    /**
     * vSphere Client: SAML.
     *
     * Arayüz kendi başına giriş almıyor; /ui/login tarayıcıyı vCenter'ın SAML
     * kimlik sağlayıcısına yolluyor, oradan dönen belge vCenter'a geri
     * gönderiliyor ve oturum çerezi o adımda yazılıyor. Üç adımın üçü de
     * sunucuda yapılabiliyor (vCenter 8'de sıra çerezsiz çalışıyor, kimlik
     * sağlayıcı isteği imzasından doğruluyor).
     */
    private function vcenter(Credential $credential, string $taban): string
    {
        $sso = VcenterSsoAddress::al($credential);

        if ($sso === null) {
            return '';
        }

        $saml = $this->istek()->asForm()->post($sso, [
            'CastleAuthorization' => 'Basic '.base64_encode(
                $credential->username.':'.$credential->password
            ),
        ]);

        if (! $saml->successful()) {
            return '';
        }

        /*
         * İLK ADIMIN ÇEREZİ TAŞINMAK ZORUNDA.
         *
         * Kimlik sağlayıcı bu adımda `CastleSession<alan>` çerezini yazıyor
         * ve ikinci adımda onu geri bekliyor. Taşınmadığında vCenter SAML
         * belgesini kabul ediyormuş gibi 200 dönüyor ama OTURUM AÇMIYOR: ne
         * yönlendirme geliyor ne de tek bir Set-Cookie. Sonuç, arayüzün
         * "oturumun yok" deyip çıkışa (SLO) gitmesi ve 401'di.
         *
         * Aynı istek iki türlü:
         *   çerezsiz : HTTP 200 · Set-Cookie yok         · oturum açılmıyor
         *   çerezli  : HTTP 302 → /ui/ · dört çerez      · oturum açılıyor
         *     (CastleSession…, VSPHERE-UI-JSESSIONID,
         *      VSPHERE-USERNAME, VSPHERE-CLIENT-SESSION-INDEX)
         */
        $ilkCerez = $this->cerezi($saml);

        $belge = $saml->body();

        if (preg_match('#name="SAMLResponse" value="([^"]*)"#', $belge, $e1) !== 1
            || preg_match('#<form method="post" action="([^"]*)"#', $belge, $e2) !== 1) {
            return '';
        }

        /*
         * Hedef AYNI makine olmalı. Adres cihazın yanıtından geliyor; ele
         * geçirilmiş bir cihaz SAML belgesini üçüncü bir yere yollatabilirdi.
         *
         * Karşılaştırma METİN ÖNEKİYLE YAPILMAZ: bizim adresimizde port var
         * (192.0.2.10:443), cihazın kurduğu adreste yok (192.0.2.10) ve ikisi
         * aynı makine olduğu hâlde önek tutmaz — vCenter oturumu hiç
         * açılmaz. Konak ve etkin port ayrı ayrı karşılaştırılır.
         */
        if (! $this->ayniMakine($e2[1], $taban)) {
            return '';
        }

        preg_match('#name="RelayState" value="([^"]*)"#', $belge, $e3);

        $ikinci = $this->istek()
            ->withHeaders($ilkCerez === '' ? [] : ['Cookie' => $ilkCerez])
            ->asForm()->post($e2[1], [
                'SAMLResponse' => html_entity_decode($e1[1], ENT_QUOTES | ENT_HTML5),
                'RelayState' => html_entity_decode($e3[1] ?? '', ENT_QUOTES | ENT_HTML5),
            ]);

        /*
         * İKİ ADIMIN ÇEREZLERİ BİRLEŞTİRİLİR. Arayüz ikisini de istiyor:
         * kimlik sağlayıcının oturumu ile istemcinin oturumu ayrı.
         */
        return $this->birlestir($ilkCerez, $this->cerezi($ikinci));
    }

    /** İki adres aynı makineyi mi gösteriyor? Port 443 varsayılandır. */
    private function ayniMakine(string $adres, string $taban): bool
    {
        $a = parse_url($adres);
        $b = parse_url($taban);

        return ($a['scheme'] ?? '') === 'https'
            && isset($a['host'], $b['host'])
            && strcasecmp($a['host'], $b['host']) === 0
            && ((int) ($a['port'] ?? 443)) === ((int) ($b['port'] ?? 443));
    }

    private function istek(): PendingRequest
    {
        return Http::withOptions([
            'verify' => false,

            /* Yönlendirme İZLENMEZ: gereken çerez ilk yanıtta yazılıyor. */
            'allow_redirects' => false,
        ])
            ->timeout(25)
            /*
             * BAĞLANTI HATASINDA YENİDEN DENENİR — yanıt hatasında DENENMEZ.
             *
             * Gömülü cihazların TLS'i yavaş: Lantronix Spider'da TCP bağlantısı
             * hızlı kurulur ama el sıkışma saniyeler sürebilir. Cihaz yoğunken
             * bu süre sınırı aşar ve kullanıcı "Beklenmeyen bir hata" görür —
             * oysa parola da yol da doğru, cihaz yalnızca yavaş.
             *
             * KOŞUL ÖNEMLİ: yalnızca ConnectionException yeniden denenir.
             * Yanıt gelmişse (yanlış parola, 403, 500) tekrar denemek kimlik
             * denemesini çoğaltırdı; kilitlenen bir hesap, zaman aşımından
             * daha pahalıdır.
             */
            ->retry(
                3,
                1500,
                fn (?Throwable $e): bool => $e instanceof ConnectionException,
                /*
                 * throw: false — YANIT GELDİYSE İSTİSNA ATILMAZ.
                 *
                 * `retry` açıkken Laravel başarısız YANITI da istisnaya
                 * çeviriyor; true bırakıldığında 403/500 dönen cihazda kod
                 * yanıtı hiç göremiyor ve "çerez yok" dalı çalışmıyordu
                 * (testte yakalandı). Bize gereken yalnızca BAĞLANTI
                 * hatasında tekrar denemek; yanıtın yorumu bu sınıfın kendi
                 * işi.
                 */
                throw: false,
            );
    }

    /**
     * İki "Cookie" değerini birleştirir; aynı ad varsa SONRAKİ kazanır.
     *
     * Sonraki kazanır çünkü ikinci adım, ilk adımın geçici çerezini
     * tazeleyebiliyor; eskisini tutmak oturumu yarıda bırakırdı.
     */
    private function birlestir(string $once, string $sonra): string
    {
        $ciftler = [];

        foreach ([$once, $sonra] as $kaynak) {
            foreach (array_filter(explode(';', $kaynak)) as $parca) {
                $parca = trim($parca);

                if (str_contains($parca, '=')) {
                    [$ad, $deger] = explode('=', $parca, 2);
                    $ciftler[trim($ad)] = $deger;
                }
            }
        }

        $sonuc = [];

        foreach ($ciftler as $ad => $deger) {
            $sonuc[] = $ad.'='.$deger;
        }

        return implode('; ', $sonuc);
    }

    /**
     * Yanıttaki Set-Cookie başlıklarını tek bir "Cookie" değerine çevirir.
     *
     * Nitelikler (Path, HttpOnly, Secure...) ATILIR: cihaza giden istekte
     * yalnızca ad=değer çiftleri anlamlı.
     */
    private function cerezi(Response $yanit): string
    {
        /*
         * SON YANIT SAKLANIR — sebebi söyleyebilmek için.
         *
         * Giriş tutmadığında elimizde yalnızca boş bir çerez kalıyordu ve
         * kullanıcıya "oturum açılamadı" demekten başka bir şey
         * söyleyemiyorduk; ekranda da HTTP 500 çıkıyordu. Cihazın döndürdüğü
         * durum kodu, sebebi ayırmaya yetiyor: 401 parola, 404 adres, 5xx
         * cihazın kendi arızası. Kullanıcı HTTP 500 yerine sebebi görmeli.
         */
        $this->sonYanit = $yanit;

        try {
            $ciftler = [];

            /*
             * Başlık adı BÜYÜK/KÜÇÜK HARFE DUYARSIZ aranır. Sunucudan sunucuya
             * değişiyor: vCenter "set-cookie" diye veriyor ve doğrudan
             * "Set-Cookie" anahtarına bakan kod hiçbir çerez bulamayıp oturumu
             * açılmadı sayardı.
             */
            $satirlar = [];

            foreach ($yanit->headers() as $ad => $degerler) {
                if (strcasecmp($ad, 'Set-Cookie') === 0) {
                    $satirlar = (array) $degerler;

                    break;
                }
            }

            foreach ($satirlar as $satir) {
                $ilk = explode(';', $satir, 2)[0];

                if (str_contains($ilk, '=')) {
                    $ciftler[] = trim($ilk);
                }
            }

            return implode('; ', $ciftler);
        } catch (Throwable) {
            return '';
        }
    }
}
