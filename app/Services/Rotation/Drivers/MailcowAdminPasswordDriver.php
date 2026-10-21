<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\ConnectsOverSsh;
use App\Services\Rotation\Drivers\Concerns\SelectsMachineSshRecord;
use App\Services\Rotation\MachineAccount;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * mailcow SÜPER YÖNETİCİ parolasını değiştirir.
 *
 * NEDEN API DEĞİL: mailcow'un OpenAPI şemasının 66 ucunun tamamına bakıldı;
 * yalnızca `domain-admin` uçları var, süper yönetici için uç YOK
 * (bkz. MailcowPasswordDriver). Geriye mailcow'un kendi yaptığı yol kalıyor.
 *
 * KAYNAK: mailcow'un resmi `helper-scripts/mailcow-reset-admin.sh` betiği
 * yönetici parolasını şöyle sıfırlıyor:
 *
 *     password=$(doveadm pw -s SSHA256 -p <parola>)
 *     mysql ... -e "INSERT INTO admin (username, password, ...) VALUES (...)"
 *
 * Yani desteklenen saklama biçimi `{SSHA256}...` ve tablo `admin`.
 * Ayrıca `hash_password()` ÖN-ÖZETLENMİŞ değerleri açıkça kabul ediyor:
 * `{SSHA256}` ile başlayan bir değer olduğu gibi kullanılıyor.
 *
 * BU YÜZDEN ÖZETİ BİZ ÜRETİRİZ. Parola sunucudan hiç çıkmaz: uzak makineye
 * yalnızca özet gider. mailcow'un kendi betiği parolayı `doveadm pw -p` ile
 * komut satırına koyuyor; bizim yolumuzda o pencere hiç açılmıyor.
 *
 * Resmi betikten bir farkımız daha var: o betik satırı SİLİP yeniden ekliyor
 * ve bu arada `domain_admins` ile `tfa` kayıtlarını da temizliyor. Biz
 * yalnızca parola sütununu GÜNCELLİYORUZ — iki adımlı doğrulama kurulmuş bir
 * hesabı parola döndürürken sessizce korumasız bırakmak kabul edilemez.
 */
class MailcowAdminPasswordDriver implements NeedsMachineAccount, PasswordDriver
{
    use ConnectsOverSsh;
    use SelectsMachineSshRecord;

    public const KEY = 'mailcow_admin';

    /** Docker komutları yavaştır; ayar verilmezse kendi tabanı kullanılır. */
    public function __construct(?int $timeout = null)
    {
        $this->timeout = $timeout ?? max(60, (int) config('rotation.ssh_timeout', 30));
    }

    protected function defaultPort(): int
    {
        return 22;
    }

    /**
     * Kurtarma hesabı açılmaz.
     *
     * Değiştiren kanal (makinenin SSH erişimi) ile değiştirilen parola zaten
     * ayrı şeyler; SSH bozulmadığı için kapı açık kalıyor. İkinci bir süper
     * yönetici açmak, olmayan bir riske karşılık gerçek bir yetki bırakmak
     * olurdu.
     */
    public function supportsRecovery(): bool
    {
        return false;
    }

    /**
     * Doğrulanabilir: saklanan özet geri okunur ve YENİ parolayla sınanır.
     *
     * Bu "yazma başarılı oldu mu" kontrolünden fazlasıdır — mailcow girişte
     * tam olarak bu hesabı yapıyor. Sınama yerelde yapılır, uzak makineye
     * parola gitmez.
     */
    public function canVerify(): bool
    {
        return true;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) => 'mailcow makinesinin adresi tanımlı değil.',
            blank($credential->username) => 'Yönetici kullanıcı adı tanımlı değil.',
            default => app(MachineAccount::class)->eksikSebep($credential),
        };
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $this->guardUsername($username);

        $hash = $this->ssha256($newPassword);

        $this->sql($session, sprintf(
            "UPDATE admin SET password = '%s' WHERE username = '%s';",
            $hash,
            $this->escapeSql($username),
        ));

        /*
         * Yazma GERÇEKTEN oldu mu? mysql sessizce sıfır satır güncelleyebilir
         * (yanlış kullanıcı adı, farklı tablo). Geri okunmazsa hiçbir şey
         * yapılmamış bir tur başarılı sayılırdı.
         */
        if ($this->storedHash($session, $username) !== $hash) {
            throw new RotationException('Parola yazıldı ama tabloda görünmüyor; kullanıcı adı doğru mu?');
        }
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        throw new RotationException('mailcow yönetici sürücüsü kurtarma hesabı açmaz.');
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        throw new RotationException('mailcow yönetici sürücüsü kurtarma hesabı açmaz.');
    }

    /**
     * Saklanan özeti okur ve verilen parolayla sınar.
     *
     * mailcow'un giriş sırasında yaptığı hesabın aynısı; tuz özetin İÇİNDE
     * taşınıyor.
     */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            /*
             * KİMLİK TURUN KULLANDIĞI YERDEN OKUNUR.
             *
             * Burada doğrudan kaydın `rotation_username` alanına bakılıyordu;
             * güncelleme hesabı KASADAN seçilmişse (rotation_credential_id) o
             * alan boş kalıyor ve doğrulama bağlanamıyordu. Tur o zaman
             * "yeni parola doğrulanamadı" deyip geri almaya kalkıyor — oysa
             * parola çoktan değişmiş oluyor. Aynı çözümleme MachineAccount'nde
             * duruyor ve turun kendisi de onu kullanıyor.
             */
            [$kimlik, $kimlikParolasi] = app(MachineAccount::class)->bul($credential)
                ?? [(string) $credential->rotation_username, (string) $credential->rotation_password];

            $session = $this->open($credential, $kimlik, $kimlikParolasi);

            try {
                $stored = $this->storedHash($session, $username);
            } finally {
                $session->close();
            }
        } catch (RotationException) {
            return false;
        }

        return $stored !== null && $this->matches($password, $stored);
    }

    /**
     * mailcow'un kendi biçimi (functions.inc.php, hash_password):
     *
     *     salt = bin2hex(random_bytes(8))                 // 16 karakter metin
     *     {SSHA256} + base64( sha256(parola . salt) . salt )
     */
    private function ssha256(string $password): string
    {
        $salt = bin2hex(random_bytes(8));

        return '{SSHA256}'.base64_encode(hash('sha256', $password.$salt, true).$salt);
    }

    private function matches(string $password, string $stored): bool
    {
        if (! str_starts_with($stored, '{SSHA256}')) {
            /* Başka bir şema ile saklanmış; yerelde sınayamayız. */
            return false;
        }

        $raw = base64_decode(substr($stored, strlen('{SSHA256}')), true);

        if ($raw === false || strlen($raw) <= 32) {
            return false;
        }

        $salt = substr($raw, 32);

        return hash_equals($raw, hash('sha256', $password.$salt, true).$salt);
    }

    private function storedHash(RotationSession $session, string $username): ?string
    {
        $output = $this->query($session, sprintf(
            "SELECT password FROM admin WHERE username = '%s';",
            $this->escapeSql($username),
        ));

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, '{')) {
                return $line;
            }
        }

        return null;
    }

    private function sql(RotationSession $session, string $statement): void
    {
        $this->query($session, $statement);
    }

    /**
     * SQL'i mysql istemcisine STANDART GİRDİDEN verir.
     *
     * Komut satırında taşınsaydı hem tırnak kaçışı iki kabuktan geçerdi hem
     * de ifade uzak makinenin süreç listesinde görünürdü.
     *
     * Veritabanı kimliği mailcow.conf'tan okunur — mailcow'un kendi betiği de
     * öyle yapıyor.
     */
    private function query(RotationSession $session, string $statement): string
    {
        $path = $this->mailcowPath();

        return $this->execute(
            $session,
            "cd {$path} && set -a && . ./mailcow.conf && set +a && "
            .'docker compose exec -T mysql-mailcow mysql -u"$DBUSER" -p"$DBPASS" "$DBNAME" -N -B',
            'mailcow veritabanı komutu çalıştırılamadı.',
            $statement."\n",
        );
    }

    private function mailcowPath(): string
    {
        $path = (string) config('rotation.mailcow_path');

        if (preg_match('#^/[A-Za-z0-9._/-]+$#', $path) !== 1) {
            throw new RotationException('mailcow dizin yolu geçersiz (config/rotation.php).');
        }

        return $path;
    }

    private function guardUsername(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username) !== 1) {
            throw new RotationException('Yönetici kullanıcı adı gönderilemeyecek bir karakter içeriyor.');
        }
    }

    private function escapeSql(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
