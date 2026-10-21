<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\ConnectsOverSsh;
use App\Services\Rotation\Drivers\Concerns\SecerMakineSshKaydi;
use App\Services\Rotation\MakineHesabi;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * mailcow üzerindeki Rspamd denetim arayüzünün parolasını değiştirir.
 *
 * ÖLÇÜLDÜ (mailcow belgeleri, "Rspamd / General Settings"):
 *  1. Özet: `docker compose exec rspamd-mailcow rspamadm pw`
 *  2. Özet `data/conf/rspamd/override.d/worker-controller.inc` içine
 *     `enable_password` olarak yazılır (`password` yazma erişimini kapatır).
 *  3. `docker compose restart rspamd-mailcow`
 *
 * Rspamd'ın hesap kavramı YOKTUR: tek bir parola, bir yapılandırma
 * dosyasında. Kurtarma hesabı açılamaz — ve gerekmez: geri dönüş kanalı
 * makinenin SSH erişimidir ve o ayrı bir kayıttır. Bu yüzden SSH kimliği
 * kaydın "döndürme hesabı" alanlarına girilir; Rspamd parolasıyla
 * bağlanılmaz.
 *
 * SINIRLAR — gerçek bir makinede ölçülmeden kapanmaz:
 *  - Parola `-p` ile komut satırında gider ve kap içindeki süreç listesinde
 *    kısa süre görünür. KAÇINILMAZ: rspamadm kaynağına bakıldı, `-p`
 *    verilmediğinde parolayı `/dev/tty` üzerinden okuyor (util.c,
 *    rspamd_read_passphrase -> open(_PATH_TTY)). `docker compose exec -T`
 *    ile tty yok, dolayısıyla standart girdiden besleme çalışmaz.
 *    Ana makinenin süreç listesinde görünmez; komut kapsayıcının içinde
 *    çalışıyor.
 *  - Parola `-p` ile gittiği için kap içindeki süreç listesinde kısa süre
 *    görünür (yukarıda); ana makinede görünmez.
 */
class RspamdPasswordDriver implements NeedsMachineAccount, PasswordDriver
{
    /*
     * İKİ TAŞIMA, İKİ `verify`. SSH taşıması "makineye bağlanabiliyor muyum"
     * diye sorar; burada doğru soru "DENETİM ARAYÜZÜ yeni parolayı kabul
     * ediyor mu" ve cevabı Rspamd'ın kendi ucu veriyor.
     */
    use ConnectsOverSsh {
        ConnectsOverSsh::verify as private sshDogrula;
    }
    use SecerMakineSshKaydi;

    public const KEY = 'rspamd';

    /** Yeniden başlatma adımına tanınan süre (saniye); ölçüm changePassword'de. */
    private const RESTART_TIMEOUT = 180;

    /** Doğrulama kaç kez sorulur. */
    private const VERIFY_ATTEMPTS = 6;

    /**
     * PAROLA MAILCOW'UN KENDİ DOSYASINA yazılır.
     *
     * `worker-controller.inc`'e yazmak yetmez: parola DEĞİŞMİŞ gibi görünür
     * ama değişmez. Sebep o dosyanın ilk satırlarında yazılı:
     *
     *     .include(try=true; priority=10)
     *         "$CONFDIR/override.d/worker-controller-password.inc"
     *
     * mailcow denetim parolasını AYRI dosyada tutuyor ve onu priority=10 ile
     * dâhil ediyor; UCL'de yüksek öncelik kazanıyor, yani ana dosyaya yazılan
     * `enable_password` sessizce eziliyor. Rspamd 4.1.0'da (mailcow) ana
     * dosyaya yazıp servisi yeniden başlattıktan sonra yeni parola da eski
     * parola da 401 alıyor — çünkü geçerli olan hâlâ ayrı dosyadaki özet.
     * Doğru dosyaya yazılınca `/auth` 200 ve "auth":"ok".
     */
    private const CONFIG = 'data/conf/rspamd/override.d/worker-controller-password.inc';

    /** Docker komutları yavaştır; ayar verilmezse kendi tabanı kullanılır. */
    public function __construct(?int $timeout = null)
    {
        $this->timeout = $timeout ?? max(60, (int) config('rotation.ssh_timeout', 30));
    }

    protected function defaultPort(): int
    {
        return 22;
    }

    public function supportsRecovery(): bool
    {
        return false;
    }

    public function canVerify(): bool
    {
        return true;
    }

    /**
     * YENİ PAROLA GERÇEKTEN GEÇİYOR MU — arayüzün kendi ucundan sorulur.
     *
     * Rspamd'ın doğrulama ucu var: denetim arayüzü kimliği HER isteğin
     * `Password` başlığında bekliyor ve `/auth` cevabı ikili; tur
     * "doğrulanamadı" ile kapanmak zorunda değil.
     *
     * mailcow üzerindeki Rspamd 4.1.0'da:
     *
     *     GET <url>/auth                 → 401 {"error":"Unauthorized"}
     *     GET <url>/auth  Password: …    → 200 {"auth":"ok",…}
     *
     * KULLANICI ADI KULLANILMAZ: Rspamd'ın hesap kavramı yok, kimlik tek bir
     * paroladan ibaret. İmzada duruyor çünkü sözleşme onu istiyor.
     *
     * Ulaşılamamak "yanlış parola" sayılır (false): tur o zaman doğrulanmamış
     * olarak kapanır ve kimse parolanın çalıştığını varsaymaz.
     */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        $taban = rtrim((string) ($credential->url ?: 'https://'.$credential->host.'/rspamd'), '/');

        /*
         * SERVİS YENİ KALKMIŞ OLABİLİR, BİRKAÇ KEZ SORULUR.
         *
         * Parola değiştirildikten hemen sonra rspamd yeniden başlatılıyor ve
         * ilk saniyelerde denetim ucu 401 döndürüyor. Tek denemeyle bakan tur
         * bunu "parola geçmedi" sanıp geri almaya kalkıyordu — oysa parola
         * doğruydu, servis henüz hazır değildi (saniyeler sonra aynı parola
         * 200 verir).
         *
         * Vazgeçmek için birkaç saniye beklenir; cevap "yanlış parola" ise
         * zaten hep 401 gelir ve tur yine doğrulanmamış kapanır.
         */
        for ($deneme = 0; $deneme < self::VERIFY_ATTEMPTS; $deneme++) {
            if ($deneme > 0) {
                /*
                 * Bekleme AYARDAN okunur; sınamalarda sıfırlanabilsin diye.
                 * Sabit beklemek, kodu doğrulayan testleri de bekletiyordu.
                 */
                sleep((int) config('rotation.rspamd_verify_backoff', 5));
            }

            try {
                if (Http::withOptions(['verify' => false])
                    ->timeout($this->timeout)
                    ->withHeaders(['Password' => $password])
                    ->get($taban.'/auth')
                    ->successful()) {
                    return true;
                }
            } catch (Throwable) {
                /* Ulaşılamadı: servis kalkıyor olabilir, tekrar sorulur. */
            }
        }

        return false;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) => 'mailcow makinesinin adresi tanımlı değil.',
            default => app(MakineHesabi::class)->eksikSebep($credential),
        };
    }

    /**
     * $username kullanılmaz: Rspamd'ın hesabı yok, tek bir parola var.
     */
    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        if (preg_match('/["\\\\\r\n]/', $newPassword) === 1) {
            throw new RotationException('Parola yapılandırma dosyasına yazılamayacak bir karakter içeriyor.');
        }

        $dizin = $this->mailcowPath();

        /* 1 — özet üretilir. Parola standart girdiden okunur, kabuk geçmişine düşmez. */
        $hash = trim($this->execute(
            $session,
            "cd {$dizin} && read -r P && docker compose exec -T rspamd-mailcow rspamadm pw --encrypt -p \"\$P\"",
            'Rspamd parola özeti üretilemedi.',
            $newPassword."\n",
        ));

        $hash = $this->extractHash($hash);

        /* 2 — özet yapılandırmaya yazılır; satır yoksa eklenir. */
        $this->execute(
            $session,
            "cd {$dizin} && touch ".self::CONFIG
            ." && sed -i '/^\\s*enable_password/d' ".self::CONFIG
            ." && printf 'enable_password = \"%s\";\\n' '{$hash}' >> ".self::CONFIG,
            'Rspamd yapılandırması yazılamadı.',
        );

        /*
         * 3 — servis yeniden başlatılır; yoksa dosya okunmaz.
         *
         * BU ADIMA AYRI SÜRE TANINIR. rspamd SIGTERM'e hemen yanıt vermiyor
         * ve docker'ın bekleme süresi (varsayılan 30 saniye) doluyor; yani
         * yeniden başlatma çoğu zaman o süre kadar sürer. SSH okuma süresi de
         * aynı olsaydı tur yarış hâlinde kalırdı: bazen tutar, bazen
         * "komut tamamlanmadı" diyerek düşerdi — üstelik parola ve
         * yapılandırma çoktan yazılmış olurdu. Kap boştayken yeniden başlatma
         * hızlıdır; hemen ardından gelen ikincisi bekleme süresini doldurur.
         *
         * Süreyi kısaltmak için stop süresini düşürmek de mümkündü
         * (`--timeout 5`) ama o, tarama sırasındaki bir postayı yarıda keser;
         * beklemek daha ucuz.
         */
        if (is_object($session->handle) && method_exists($session->handle, 'setTimeout')) {
            $session->handle->setTimeout(self::RESTART_TIMEOUT);
        }

        $this->execute(
            $session,
            "cd {$dizin} && docker compose restart rspamd-mailcow",
            'Rspamd yeniden başlatılamadı.',
        );
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        throw new RotationException('Rspamd sürücüsü kurtarma hesabı açmaz.');
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        throw new RotationException('Rspamd sürücüsü kurtarma hesabı açmaz.');
    }

    /**
     * Rspamd'ın özeti tek satır döndürmesi beklenir ama `docker compose exec`
     * çıktıya uyarı satırları karıştırabiliyor; özet satırı ayıklanır.
     *
     * ÖNEK: rspamadm özeti `$<id>$<tuz>$<anahtar>` biçiminde basıyor ve id
     * kaynaktaki pbkdf listesinden geliyor — pbkdf2 = 1, catena = 2
     * (util.c, pbkdf_list). Varsayılan tür catena, yani `$2$`. İkisi de
     * kabul edilir.
     *
     * Bulunamazsa hata verilir: yanlış bir metni parola özeti sanıp dosyaya
     * yazmak Rspamd arayüzünü kilitlerdi.
     */
    private function extractHash(string $output): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '$2$') || str_starts_with($line, '$1$')) {
                return $line;
            }
        }

        throw new RotationException('Rspamd parola özeti çıktıda bulunamadı.');
    }

    private function mailcowPath(): string
    {
        $path = (string) config('rotation.mailcow_path');

        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1) {
            throw new RotationException('mailcow dizin yolu geçersiz (config/rotation.php).');
        }

        return $path;
    }
}
