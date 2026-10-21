<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\DeviceUnreachableException;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use phpseclib3\Net\SSH2;

/**
 * Lantronix Spider / SpiderDuo (SLS, KVM over IP) kullanıcı parolasını değiştirir.
 *
 * Cihazda uçtan uca denendi.
 *
 * ATEN'in aksine burada BELGELENMİŞ bir komut satırı var (Lantronix kullanım
 * kılavuzu, "11: Command Reference"), bu yüzden web arayüzünü çözmeye gerek
 * kalmadı:
 *
 *     set user add|edit <login> [group Admin|default|None] [fullname ...]
 *     set user password <login>     → "New password:" / "Retype new password:"
 *     set user delete <login>
 *     show user
 *
 * SATIR SONU CR OLMALI. `\n` gönderildiğinde cihaz komutu YANKILIYOR ama
 * çalıştırmıyor: 14 baytlık yankı geliyor, sonra sessizlik. Ölçüldü; `\r` ile
 * aynı komut hemen yanıt verdi. Bu, sessiz bir takılma olurdu — zaman aşımına
 * kadar bekleyip "cihaz yanıt vermedi" denirdi, oysa komut hiç koşmamıştı.
 *
 * DOĞRULAMA SSH'TAN DEĞİL WEB'DEN: parolası döndürülen hesabın CLI yetkisi
 * olmak zorunda değil. Ölçüldü — 'default' (Unknown) grubundaki bir kullanıcı
 * SSH'a giremiyor ama web arayüzüne giriyor. SSH ile doğrulasaydık, yetkisi
 * olmayan her hesapta tur "doğrulanamadı" ile kapanırdı. Web girişi
 * (POST /auth.asp) her kullanıcı için çalışıyor: başarıda 302, başarısızlıkta
 * giriş sayfası dönüyor.
 *
 * AYRI SÜRÜCÜ: bu dosya YALNIZCA Lantronix SLS ailesi içindir. ATEN CN8000A
 * bambaşka konuşuyor (web /Inquery uçları, UID ile güncelleme) ve kendi
 * sürücüsünde durur.
 */
class LantronixSpiderPasswordDriver implements PasswordDriver
{
    public const KEY = 'lantronix_spider';

    /** CLI istemi: [kullanıcı@konak]> */
    private const ISTEM = '/\]>\s*$/';

    public function __construct(private ?int $timeout = null)
    {
        $this->timeout ??= (int) config('rotation.timeout', 20);
    }

    /**
     * Kurtarma hesabı açılabilir ve gerekir.
     *
     * Döndürülen hesap yönetici olabilir; turun ortasında kendi kapımızı
     * kapatmamak için bağımsız bir giriş şart.
     */
    public function supportsRecovery(): bool
    {
        return true;
    }

    /** Yeni parola gerçek bir web girişiyle kanıtlanıyor. */
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
            return 'Döndürme kimliği yok: cihazda Admin grubunda bir hesap gerekiyor.';
        }

        return null;
    }

    /**
     * Art arda bağlantı denemesi ve aralar (saniye).
     *
     * Bir tur içinde cihaza dört
     * kez bağlanılıyor (yönetim oturumu, kurtarma oturumu, silme, onarım) ve
     * cihaz kapanan oturumun ardından kısa bir süre 22'yi KAPATIYOR — yeni
     * bağlantı "Connection refused" (errno 111) alıyor. Parolayı değiştirip
     * kurtarma hesabını silemeyen bir tur, cihazda açık yetkili bir hesap
     * bırakır; bu yüzden reddedilen bağlantı hatayla değil, bekleyip yeniden
     * denemeyle karşılanır.
     *
     * Yalnızca BAĞLANTI reddi tekrarlanır. Yanlış parola tekrarlanmaz: cihaz
     * birkaç hatalı denemeden sonra hesabı kilitler ve tur, kurtarılabilir bir
     * hatayı kalıcı bir soruna çevirmiş olurdu.
     */
    private const DENEME = 4;

    private const BEKLE = [2, 5, 10];

    /**
     * CLI'daki değişikliğin web arayüzüne geçmesi için beklenen süre (saniye).
     *
     * İki alt sistem aynı anda güncellenmiyor; ölçülen gecikme saniyeler
     * mertebesinde.
     */
    private const YAYILMA = 3;

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        [$ssh, $girdi] = $this->baglan($credential, $username, $password);

        if (! $girdi) {
            throw new RotationException('Cihaza giriş yapılamadı: kullanıcı adı ya da parola kabul edilmedi.');
        }

        /*
         * CLI etkileşimli: komutlar bir kabuk üzerinden gidiyor, exec ile
         * çalıştırılamıyor. PTY olmadan istem hiç gelmiyor.
         */
        $ssh->enablePTY();
        $ssh->setTimeout($this->timeout);
        $ssh->read(self::ISTEM, SSH2::READ_REGEX);

        return new RotationSession(
            ['ssh' => $ssh, 'konak' => $credential->host],
            $username,
            $password,
            static fn () => $ssh->disconnect(),
        );
    }

    /**
     * Bağlanır; yalnızca bağlantı reddinde bekleyip yeniden dener.
     *
     * @return array{0: SSH2, 1: bool}
     */
    private function baglan(Credential $credential, string $username, string $password): array
    {
        $son = null;

        for ($deneme = 0; $deneme < self::DENEME; $deneme++) {
            if ($deneme > 0) {
                sleep(self::BEKLE[min($deneme - 1, count(self::BEKLE) - 1)]);
            }

            $ssh = new SSH2($credential->host, $credential->port ?: 22, $this->timeout);

            try {
                return [$ssh, $ssh->login($username, $password)];
            } catch (\Throwable $e) {
                $son = $e;

                /* Kapı açık ama kimlik reddedildiyse tekrar denemenin anlamı yok. */
                if (! $this->kapiKapali($e->getMessage())) {
                    break;
                }
            }
        }

        $mesaj = "Cihaza bağlanılamadı ({$credential->host}): ".($son?->getMessage() ?? 'bilinmeyen hata');

        throw $this->kapiKapali($son?->getMessage() ?? '')
            ? new DeviceUnreachableException($mesaj)
            : new RotationException($mesaj);
    }

    /** Hata, TCP bağlantısının hiç kurulamadığını mı söylüyor? */
    private function kapiKapali(string $mesaj): bool
    {
        $izler = [
            'Connection refused', 'Connection timed out', 'Cannot connect', 'No route to host',
            /* Cihaz portu açıyor ama SSH karşılamasını göndermeden kapatıyor. */
            'Error reading SSH identification string', 'Connection closed by server',
        ];

        foreach ($izler as $iz) {
            if (str_contains($mesaj, $iz)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tam yetkili geçici hesap açar.
     *
     * Admin grubuna alınır: kurtarma hesabının işi, asıl hesap bozulursa
     * parolayı geri alabilmek. Yetkisiz bir hesap bu işi göremez.
     */
    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $c = $this->calistir($session, "set user add {$username} group Admin");

        if (! str_contains($c, 'successfully created')) {
            throw new RotationException('Kurtarma hesabı açılamadı: '.$this->ozet($c));
        }

        $this->parolaAta($session, $username, $password);
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        if (! $this->kullaniciVarMi($session, $username)) {
            throw new RotationException("Cihazda '{$username}' adlı hesap yok.");
        }

        $this->parolaAta($session, $username, $newPassword);
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        if (! $this->kullaniciVarMi($session, $username)) {
            return; // Zaten yok; geri dönüş yolunda da çalışmalı.
        }

        /*
         * Cihaz ONAY SORUYOR ve varsayılanı HAYIR:
         *     Do you want to delete user 'x'? [no]
         * Yanıtlanmazsa silme sessizce iptal olur ve kurtarma hesabı cihazda
         * kalır — turun en tehlikeli sonu: geride tam yetkili, parolası
         * kimsede olmayan bir hesap. Bu yüzden onay açıkça verilir.
         */
        $c = $this->yaz($session, "set user delete {$username}", '/\[no\]\s*$|\]>\s*$/');

        if (str_contains(strtolower($c), '[no]')) {
            $c = $this->calistir($session, 'yes');
        }

        if (! str_contains(strtolower($c), 'success')) {
            throw new RotationException('Kurtarma hesabı silinemedi: '.$this->ozet($c));
        }
    }

    /**
     * Yeni parolayı WEB girişiyle kanıtlar.
     *
     * SSH kullanılmaz: parolası döndürülen hesabın CLI yetkisi olmayabilir
     * (ölçüldü). Web girişi her kullanıcı için çalışıyor.
     *
     * İstisna atmaz; kurtarma yolu karar verebilsin diye yalnızca doğru/yanlış.
     */
    /**
     * Yeni parolayla gerçekten girilebiliyor mu.
     *
     * TEK BİR "HAYIR" KESİN DEĞİLDİR. Parola CLI'dan değişiyor, doğrulama web
     * arayüzünden yapılıyor ve ikisi ayrı alt sistem: CLI "successfully
     * changed" dedikten hemen sonra yapılan web girişi reddedilebiliyor, aynı
     * parola SSH'ta çalışıyor ve bir süre sonra web de kabul ediyor. O
     * "hayır" gerçek sanılsaydı kasa güncellenmez, cihazda yeni, kasada eski
     * parola kalırdı.
     *
     * Bu yüzden sıra şu: web → (reddedildiyse) SSH → (o da reddettiyse) web.
     * SSH ayrı bir kapıdır; parolayı orada kanıtlamak web'in gecikmesinden
     * etkilenmez. Deneme sayısı ÜÇTE tutulur: cihaz art arda hatalı girişten
     * sonra hesabı kilitler ve doğrulama, kurtarılabilir bir durumu kalıcı
     * bir soruna çeviremez.
     */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        if ($this->webGirisi($credential, $username, $password)) {
            return true;
        }

        sleep(self::YAYILMA);

        /*
         * SSH'ı kabul etmeyen hesap olabilir (CLI yetkisi olmayan kullanıcı);
         * oradaki ret bir şey KANITLAMAZ, yalnızca web'e geri dönülür.
         */
        try {
            $this->open($credential, $username, $password)->close();

            return true;
        } catch (DeviceUnreachableException $e) {
            throw $e;
        } catch (RotationException) {
            /* Kapı açık, kimlik kabul edilmedi: web'e son bir şans. */
        }

        sleep(self::YAYILMA);

        return $this->webGirisi($credential, $username, $password);
    }

    /**
     * Web girişi — ulaşılamama hâlinde bekleyip yeniden dener.
     *
     * Dönen `false` yalnızca "cihaz kimliği kabul etmedi" demektir; cihaza
     * hiç ulaşılamadıysa istisna fırlar (bkz. DeviceUnreachableException).
     */
    private function webGirisi(Credential $credential, string $username, string $password): bool
    {
        $son = null;

        for ($deneme = 0; $deneme < self::DENEME; $deneme++) {
            if ($deneme > 0) {
                sleep(self::BEKLE[min($deneme - 1, count(self::BEKLE) - 1)]);
            }

            try {
                return $this->girisDene($credential, $username, $password);
            } catch (DeviceUnreachableException $e) {
                $son = $e;
            }
        }

        throw $son;
    }

    /** Tek bir giriş denemesi; cihaza ulaşılamazsa istisna fırlatır. */
    private function girisDene(Credential $credential, string $username, string $password): bool
    {
        $ch = curl_init("https://{$credential->host}/auth.asp");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'nickname' => '',
                'login' => $username,
                'password' => $password,
                'action_login' => 'Login',
            ]),

            /* Cihazın kendi imzaladığı sertifika ve eski şifre paketleri. */
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=0',
        ]);

        $govde = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata = curl_error($ch);

        /*
         * Ulaşılamamak `false` DEĞİLDİR: `false` "parola yanlış" diye okunur
         * ve cihaza hiç ulaşamamışken bunu söylemek kasayı cihazdan
         * ayrıştırır (bkz. DeviceUnreachableException).
         */
        if ($govde === false) {
            throw new DeviceUnreachableException("Cihaza ulaşılamadı ({$credential->host}): {$hata}");
        }

        /*
         * Başarıda cihaz 302 ile yönlendiriyor; başarısızlıkta giriş sayfasını
         * geri veriyor. Yalnızca koda bakmak yetmez — bazı hatalarda da 200
         * dönüyor, bu yüzden içerik de sınanır.
         */
        return $kod === 302 && ! str_contains((string) $govde, 'Authentication');
    }

    // ---------------------------------------------------------------------
    //  CLI
    // ---------------------------------------------------------------------

    /**
     * Parolayı atar.
     *
     * Cihaz parolayı komut satırında KABUL ETMEZ; iki kez sorar. Bu aslında
     * iyi: parola komut geçmişine ve süreç listesine düşmüyor.
     */
    private function parolaAta(RotationSession $session, string $username, string $password): void
    {
        $c = $this->yaz($session, "set user password {$username}", '/password:\s*$/i');

        if (! preg_match('/password:/i', $c)) {
            throw new RotationException('Cihaz parola sormadı: '.$this->ozet($c));
        }

        $this->yaz($session, $password, '/password:\s*$/i');      // "New password:"
        $son = $this->calistir($session, $password);              // "Retype new password:"

        if (! str_contains(strtolower($son), 'successfully changed')) {
            throw new RotationException('Parola değiştirilemedi: '.$this->ozet($son));
        }
    }

    private function kullaniciVarMi(RotationSession $session, string $username): bool
    {
        $liste = $this->calistir($session, 'show user');

        foreach (preg_split('/\R/', $liste) ?: [] as $satir) {
            if (preg_match('/^\s*\d+\s+(\S+)/', $satir, $e) && strcasecmp($e[1], $username) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Komutu çalıştırır ve istem geri gelene kadar okur. */
    private function calistir(RotationSession $session, string $komut): string
    {
        return $this->yaz($session, $komut, self::ISTEM);
    }

    private function yaz(RotationSession $session, string $metin, string $bekle): string
    {
        /** @var SSH2 $ssh */
        $ssh = $session->handle['ssh'];

        /*
         * SATIR SONU CR. "\n" ile cihaz komutu yankılayıp çalıştırmıyor;
         * istem hiç geri gelmiyor ve çağrı zaman aşımına kadar asılı kalıyor.
         */
        $ssh->write($metin."\r");
        $ssh->setTimeout($this->timeout);

        $c = (string) $ssh->read($bekle, SSH2::READ_REGEX);

        /* Terminal renk/konum kodları temizlenir; yoksa eşleşmeler kayıyor. */
        return preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $c) ?? $c;
    }

    /** Hata metnini insanca kısaltır. */
    private function ozet(string $c): string
    {
        $temiz = trim(preg_replace('/\[[^\]]*\]>\s*$/', '', trim($c)) ?? $c);
        $satir = array_values(array_filter(array_map('trim', preg_split('/\R/', $temiz) ?: [])));

        return $satir === [] ? 'cihaz boş yanıt verdi' : (string) end($satir);
    }
}
