<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\DeviceUnreachableException;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * ATEN CN8000A (KVM over IP) kullanıcı parolasını değiştirir.
 *
 * Protokol akışı (cihazda uçtan uca denendi):
 *
 *  1. Kapak sayfası oturuma özel bir yola yönlendirir (ör. /0000ABCD...).
 *  2. O sayfadaki KVMIP_TARGETID okunur.
 *  3. Giriş, TEK alanda gönderilir:
 *         KVMIP_LOGIN = kaçışlı(kullanıcı) ␣ kaçışlı(parola)
 *                       ␣ kaçışlı(konak) ␣ kaçışlı(targetid)
 *     Kaçış tablosu cihazın kendi convert() fonksiyonundan alındı; alanlar
 *     BOŞLUKLA ayrıldığı için boşluk ve özel karakterler kaçırılmak zorunda.
 *  4. Oturum `sid` çerezinde taşınır.
 *  5. Kullanıcı listesi: POST /Inquery?query=16
 *     → com_accusers=<uid>=<ad>=<tür>=<açıklama>=<durum>=...
 *  6. Parola değişimi: oturum yoluna POST, /Inquery?update=29 alanıyla.
 *
 * NEDEN SSH DEĞİL: CN8000A'da SSH ve Telnet cihaz yönetimi için değil, RS-232
 * seri konsol geçişi içindir (ATEN kılavuzu). Denendi: SSH girişi başarılı
 * oluyor ama oturum boş geliyor; yönetim komutu yok. Tek yönetim yolu budur.
 *
 * ÜÇ TUHAFLIK — sürücü bunları bilerek ele alır:
 *  - Cihaz çerezi bozuk gönderir: "sid=DEGER domain=..." (noktalı virgül yok).
 *    Değer ilk boşluğa kadardır; tamamı alınırsa oturum tanınmaz.
 *  - Yanıtları başlıksız gzip gönderebilir; içerik el ile açılır.
 *  - Eşzamanlı oturumu sevmez. Giriş ile istek arasına başka çağrı girerse
 *    oturum kapanır ve `com_close=` döner. Bu yüzden her işlem, girişten
 *    hemen sonra aynı oturumda yapılır.
 *
 * KULLANICI ADI DEĞİL UID: güncellemede `acnt_user_orgun` alanı kullanıcının
 * UID'sini ister. Kullanıcı adı yazıldığında cihaz "u" yanıtı verip HİÇBİR
 * ŞEY YAPMAZ — sessiz başarısızlık. UID her seferinde listeden okunur.
 *
 * İKİNCİ MODEL: bu sürücü YALNIZCA CN8000A içindir. Başka bir ATEN modeli
 * farklı sorgu/güncelleme kodları ya da alan adları kullanabilir; onun için
 * ayrı bir sürücü yazılır, burası değiştirilmez.
 */
class AtenCn8000PasswordDriver implements PasswordDriver
{
    public const KEY = 'aten_cn8000';

    /** Cihazın kendi convert() tablosu; alanlar boşlukla ayrıldığı için şart. */
    private const KACIS = [
        '-' => '--', '"' => '-0', '|' => '-1', '#' => '-2', ' ' => '-3',
        '*' => '-4', '&' => '-5', '+' => '-6', '%' => '-7',
    ];

    /**
     * Ulaşılamama hâlinde deneme sayısı ve aralar (saniye).
     *
     * Cihazın gömülü sunucusu tek bağlantılık: art arda gelen isteklerde
     * "Empty reply from server" dönüyor. Bir turda cihaza altı kez
     * bağlanılıyor, o yüzden geçici ret hatayla değil beklemeyle karşılanır.
     */
    private const DENEME = 4;

    private const BEKLE = [2, 5, 10];

    private const SORGU_KULLANICILAR = 16;

    private const GUNCELLE_KULLANICI = 29;

    private const GUNCELLE_SIL = 46;

    /** Yönetici / kullanıcı / seçmeli hesap türleri (acnt_user_type). */
    private const TUR_YONETICI = 0;

    private const TUR_KULLANICI = 1;

    public function __construct(private ?int $timeout = null)
    {
        $this->timeout ??= (int) config('rotation.timeout', 20);
    }

    /**
     * Kurtarma hesabı AÇILABİLİR ve gerekir.
     *
     * Parolasını değiştirdiğimiz hesapla bağlanmıyoruz — yönetici hesabıyla
     * bağlanıyoruz — ama yönetici hesabının parolası da döndürülebilir. Geçici
     * hesap, turun ortasında kapının kapanmamasını garanti eder.
     */
    public function supportsRecovery(): bool
    {
        return true;
    }

    /** Yeni parola gerçek bir girişle kanıtlanabiliyor. */
    public function canVerify(): bool
    {
        return true;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        if (blank($credential->host)) {
            return 'Cihaz adresi boş.';
        }

        if (blank($credential->rotation_username) || blank($credential->rotation_password)) {
            return 'Döndürme kimliği yok: cihazda yönetici yetkili bir hesap gerekiyor.';
        }

        return null;
    }

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $oturum = $this->giris($credential->host, $username, $password);

        /*
         * Kapanışta geçici çerez dosyası silinir. Kalsaydı geçerli bir oturum
         * kimliği diskte, sürecin ömründen uzun süre dururdu.
         */
        return new RotationSession(
            $oturum,
            $username,
            $password,
            static fn () => @unlink($oturum['cerez']),
        );
    }

    /**
     * Tam yetkili geçici hesap açar.
     *
     * `acnt_user_orgun` BOŞ bırakılır: cihaz bunu "yeni kayıt" olarak okur.
     * Dolu olsaydı var olan bir hesabın üstüne yazılırdı.
     */
    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->kullaniciYaz($session, '', $username, $password, self::TUR_YONETICI, 'EYS kurtarma');

        if ($this->uidBul($session, $username) === null) {
            throw new RotationException('Kurtarma hesabı açılamadı: cihaz kaydı oluşturmadı.');
        }
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $mevcut = $this->kullanici($session, $username);

        if ($mevcut === null) {
            throw new RotationException("Cihazda '{$username}' adlı hesap yok.");
        }

        /*
         * Hesabın türü ve açıklaması BİRLİKTE geri yazılır: form tek seferde
         * bütün alanları gönderiyor, eksik bırakılan alan sıfırlanır. Parolayı
         * değiştirirken kullanıcının yetkisini düşürmek kabul edilemez.
         */
        $this->kullaniciYaz(
            $session,
            (string) $mevcut['uid'],
            $username,
            $newPassword,
            (int) $mevcut['tur'],
            (string) $mevcut['aciklama'],
        );
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $uid = $this->uidBul($session, $username);

        if ($uid === null) {
            return; // Zaten yok; silme çağrısı geri dönüş yolunda da çalışmalı.
        }

        $this->istek($session, '/Inquery', [
            'update' => (string) self::GUNCELLE_SIL,
            'uid' => (string) $uid,
        ]);
    }

    /**
     * Yeni parolanın gerçekten çalıştığını KANITLAR: yeni bir oturum açar.
     *
     * İstisna atmaz; kurtarma yolunun karar verebilmesi için yalnızca doğru
     * ya da yanlış döner.
     */
    /**
     * Yeni parolayla GERÇEKTEN giriş yapılabiliyor mu.
     *
     * Ulaşılamamak yutulmaz (bkz. DeviceUnreachableException): `false`
     * "parola yanlış" demektir ve cihaza hiç ulaşamamışken bunu söylemek
     * kasayı cihazdan ayrıştırır.
     */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $oturum = $this->giris($credential->host, $username, $password);
            @unlink($oturum['cerez']);

            return true;
        } catch (DeviceUnreachableException $e) {
            /* Ulaşılamamak yutulmaz: `false` "parola yanlış" diye okunur. */
            throw $e;
        } catch (RotationException) {
            /* Cihaz yanıt verdi ve kimliği kabul etmedi. */
            return false;
        }
    }

    // ---------------------------------------------------------------------
    //  Cihaz protokolü
    // ---------------------------------------------------------------------

    /** @return array{konak: string, yol: string, sid: string, cerez: string} */
    private function giris(string $konak, string $kullanici, string $parola): array
    {
        $cerez = tempnam(sys_get_temp_dir(), 'aten');

        $kapak = $this->cagir($konak, "https://{$konak}/", $cerez);

        if (! preg_match('#/[A-F0-9]{20,}#', $kapak, $e)) {
            throw new RotationException('Cihaz oturum adresi vermedi; ATEN arayüzü değil olabilir.');
        }

        $yol = $e[0];
        $sayfa = $this->cagir($konak, "https://{$konak}{$yol}", $cerez);

        if (! preg_match('/name="KVMIP_TARGETID"[^>]*value\s*=\s*"([^"]*)"/', $sayfa, $t)) {
            throw new RotationException('Giriş sayfasında KVMIP_TARGETID yok.');
        }

        $alan = implode(' ', [
            $this->kacir($kullanici), $this->kacir($parola),
            $this->kacir($konak), $this->kacir($t[1]),
        ]);

        $govde = $this->cagir($konak, "https://{$konak}{$yol}", $cerez, [
            'KVMIP_GMTIME' => (string) time(),
            'KVMIP_DIFFTIME' => '180',
            'KVMIP_LOGIN' => $alan,
            'KVMIP_TARGETID' => $t[1],
        ], takip: true);

        if (! str_contains($govde, 'Logout')) {
            @unlink($cerez);
            throw new RotationException('Cihaza giriş yapılamadı: kullanıcı adı ya da parola kabul edilmedi.');
        }

        return ['konak' => $konak, 'yol' => $yol, 'sid' => $this->sid($cerez), 'cerez' => $cerez];
    }

    /**
     * Çerezden oturum kimliği.
     *
     * Cihaz "sid=DEGER domain=..." gönderiyor (noktalı virgül yok), bu yüzden
     * istemci tamamını değer sanıyor. İlk boşluğa kadarı alınır.
     */
    private function sid(string $cerez): string
    {
        foreach (file($cerez) ?: [] as $satir) {
            $p = explode("\t", rtrim($satir, "\n"));

            if (count($p) >= 7 && $p[5] === 'sid') {
                return strtok(trim($p[6]), ' ') ?: '';
            }
        }

        throw new RotationException('Cihaz oturum çerezi vermedi.');
    }

    private function kacir(string $s): string
    {
        return strtr($s, self::KACIS);
    }

    /** @return array<int, array{uid: int, ad: string, tur: int, aciklama: string}> */
    private function kullanicilar(RotationSession $session): array
    {
        $yanit = $this->istek($session, '/Inquery', ['query' => (string) self::SORGU_KULLANICILAR]);

        $bas = strpos($yanit, 'com_accusers=');

        if ($bas === false) {
            throw new RotationException('Cihaz kullanıcı listesini vermedi (oturum kapanmış olabilir).');
        }

        $ham = substr($yanit, $bas + strlen('com_accusers='));
        $parca = explode('=', trim($ham));
        $liste = [];

        /* Beşli gruplar: uid, ad, tür, açıklama, durum. */
        for ($i = 0; $i + 4 < count($parca) + 1; $i += 5) {
            if (! isset($parca[$i], $parca[$i + 1])) {
                break;
            }

            $liste[] = [
                'uid' => (int) trim($parca[$i]),
                'ad' => trim($parca[$i + 1]),
                'tur' => (int) trim($parca[$i + 2] ?? (string) self::TUR_KULLANICI),
                'aciklama' => trim($parca[$i + 3] ?? ''),
            ];
        }

        return $liste;
    }

    /** @return array{uid: int, ad: string, tur: int, aciklama: string}|null */
    private function kullanici(RotationSession $session, string $ad): ?array
    {
        foreach ($this->kullanicilar($session) as $k) {
            if (strcasecmp($k['ad'], $ad) === 0) {
                return $k;
            }
        }

        return null;
    }

    private function uidBul(RotationSession $session, string $ad): ?int
    {
        return $this->kullanici($session, $ad)['uid'] ?? null;
    }

    /**
     * Kullanıcı ekler ya da günceller.
     *
     * $orgun boşsa ekleme, doluysa (UID) güncellemedir. Form bütün alanları
     * tek seferde gönderdiği için yetkiler de birlikte yazılır.
     */
    private function kullaniciYaz(
        RotationSession $session,
        string $orgun,
        string $ad,
        string $parola,
        int $tur,
        string $aciklama,
    ): void {
        $o = $session->handle;

        $alanlar = [
            '/Inquery?update' => (string) self::GUNCELLE_KULLANICI,
            'acnt_user_orgun' => $orgun,
            'acnt_user_un' => $ad,
            'acnt_user_pwd' => $parola,
            'acnt_user_cpwd' => $parola,
            'acnt_user_desc' => $aciklama,
            'acnt_user_type' => (string) $tur,
            'acnt_user_devman' => $tur === self::TUR_YONETICI ? '1' : '0',
            'acnt_user_syslog' => '0',
            'acnt_user_winclient' => '1',
            'acnt_user_javaclient' => '1',
            'acnt_user_telnetclient' => '0',
            'acnt_user_viewonly' => '0',
            'acnt_user_powerman' => '0',
            'acnt_user_forcegray' => '0',
            'acnt_user_sshclient' => '0',
            'acnt_user_vm' => '0',
            'xid' => (string) (random_int(0, 999999) / 1000000),
            'SID' => $o['sid'],
        ];

        $this->cagir($o['konak'], "https://{$o['konak']}{$o['yol']}", $o['cerez'], $alanlar);
    }

    /** @param array<string, string> $sorgu */
    private function istek(RotationSession $session, string $yol, array $sorgu): string
    {
        $o = $session->handle;
        $sorgu['SID'] = $o['sid'];
        $sorgu['xid'] = (string) (random_int(0, 999999) / 1000000);

        return $this->cagir(
            $o['konak'],
            "https://{$o['konak']}{$yol}?".http_build_query($sorgu),
            $o['cerez'],
            [],
            baslik: ['Ignore-Data: 1'],
            bos: true,
        );
    }

    /**
     * Cihazla tek bir HTTP çağrısı.
     *
     * @param  array<string, string>  $veri
     * @param  array<int, string>  $baslik
     */
    private function cagir(
        string $konak,
        string $url,
        string $cerez,
        array $veri = [],
        array $baslik = [],
        bool $takip = false,
        bool $bos = false,
    ): string {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_COOKIEFILE => $cerez,
            CURLOPT_COOKIEJAR => $cerez,
            CURLOPT_FOLLOWLOCATION => $takip,

            /*
             * Cihaz eski şifre paketleri konuşuyor ve kendi imzaladığı bir
             * sertifika sunuyor. Doğrulama kapalı: adres kasada duruyor ve
             * cihazın sertifikasını bir otorite imzalamıyor. Bu, cihazın
             * kendi sınırı — sürücünün tercihi değil.
             */
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=0',
        ]);

        if ($baslik !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $baslik);
        }

        if ($veri !== [] || $bos) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $veri === [] ? '' : http_build_query($veri));
        }

        /*
         * YENİDEN DENEME BURADA, tek tek çağıranlarda değil.
         *
         * Cihazın gömülü sunucusu aynı anda tek bağlantı kaldırıyor ve bir
         * tur sırasında ona altı kez gidiliyor (giriş, kullanıcı listesi,
         * yazma, doğrulama, silme). Meşgulken bağlantıyı yanıt vermeden
         * kapatıyor: cURL "Empty reply from server" diyor.
         *
         * Önceki turun hemen ardından başlatılan bir tur ilk adımda bu
         * hatayla düşer ve cihazda kurtarma hesabı bırakabilir. Geçici
         * meşguliyet, hata değil bekleme sebebidir.
         */
        $yanit = false;
        $hata = '';

        for ($deneme = 0; $deneme < self::DENEME; $deneme++) {
            if ($deneme > 0) {
                sleep(self::BEKLE[min($deneme - 1, count(self::BEKLE) - 1)]);

                /* Aynı tanıtıcı ikinci kez çalıştırılamaz. */
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            }

            $yanit = curl_exec($ch);

            if ($yanit !== false) {
                break;
            }

            $hata = curl_error($ch);
        }

        if ($yanit === false) {
            throw new DeviceUnreachableException("Cihaza ulaşılamadı ({$konak}): {$hata}");
        }

        /* Cihaz başlıksız gzip gönderebiliyor; içerik el ile açılır. */
        if (str_starts_with($yanit, "\x1f\x8b")) {
            $acik = @gzdecode($yanit);
            $yanit = $acik === false ? $yanit : $acik;
        }

        return $yanit;
    }
}
