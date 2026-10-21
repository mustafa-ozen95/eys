<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\ConnectsOverSsh;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * Linux hesaplarını SSH üzerinden yönetir.
 *
 * Ayrıcalık: bağlanan hesap root ise komutlar doğrudan çalışır. Değilse
 * `sudo -S` kullanılır ve parola standart girdiden verilir — bu yüzden
 * döndürme hesabının sudo yetkisi olmalıdır.
 */
class SshPasswordDriver implements PasswordDriver
{
    use ConnectsOverSsh;

    public const KEY = 'ssh';

    /**
     * Zaman aşımı AYARDAN gelir.
     *
     * Sabit bir süre yazılmaz; config/rotation.php'deki ssh_timeout buraya
     * ulaşmalı: meşgul bir makinede `chpasswd` kısa bir sabiti aşabilir;
     * tur "değiştiremedim" diyerek kapanır ama parola makinede DEĞİŞMİŞ olur.
     */
    public function __construct(?int $timeout = null)
    {
        $this->timeout = $timeout ?? (int) config('rotation.ssh_timeout', 30);
    }

    protected function defaultPort(): int
    {
        return 22;
    }

    public function supportsRecovery(): bool
    {
        return true;
    }

    public function canVerify(): bool
    {
        return true;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) => 'Adres tanımlı değil.',
            blank($credential->username) => 'Kullanıcı adı tanımlı değil.',
            blank($credential->password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            default => null,
        };
    }

    /**
     * Kurtarma hesabı: kabuğu olan, sudo/wheel grubuna ekli bir kullanıcı.
     *
     * Grup adı dağıtıma göre değişiyor; ikisi de denenir ve biri tutarsa
     * yeter. Hesabın gerçekten işe yaradığı zaten ayrıca KANITLANIYOR
     * (bkz. PasswordRotator), bu yüzden burada iki ihtimali denemek güvenli.
     */
    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->guardName($username);

        $this->run(
            $session,
            "useradd -m -s /bin/sh {$username} && (usermod -aG sudo {$username} || usermod -aG wheel {$username})",
            'Kurtarma hesabı açılamadı.',
        );

        $this->changePassword($session, $username, $password);
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $this->guardName($username);

        if (preg_match('/[\r\n:]/', $newPassword) === 1) {
            throw new RotationException('Parola bu yöntemle gönderilemeyecek bir karakter içeriyor.');
        }

        /* chpasswd "kullanıcı:parola" satırını standart girdiden okur. */
        $this->run($session, 'chpasswd', 'Parola değiştirilemedi.', "{$username}:{$newPassword}\n");
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);

        /* -f: hesap açık oturumdaysa da silinsin; -r: ev dizini de gitsin. */
        $this->run($session, "userdel -r -f {$username}", 'Kurtarma hesabı silinemedi.');
    }

    /**
     * Komutu gerekiyorsa sudo ile çalıştırır.
     *
     * sudo parolayı standart girdinin İLK satırından okur; komutun kendi
     * girdisi ondan sonra gelir.
     *
     * AlmaLinux 9 ve Ubuntu 26.04'te girdi tüketen bir
     * komut (chpasswd) `sudo -S` ile çalıştırıldığında SONSUZA KADAR
     * BEKLİYOR. Sudo parolayı okuduktan sonra uçbirim tamponunu temizliyor
     * ve komutun kendi girdisi onunla birlikte siliniyor. Tur
     * "değiştiremedim" diyerek kapanıyordu, oysa bazı makinelerde parola
     * değişmiş oluyordu — kasa ile makine ayrışıyordu.
     *
     * Bu yüzden İKİ AŞAMA: önce `sudo -v` ile kimlik doğrulanır (girdide
     * yalnızca parola vardır), sonra komut `sudo -n` ile çalışır ve girdiyi
     * yalnızca o okur. Parola yine komut satırına değil, standart girdiye
     * yazılır.
     *
     * Girdi tüketen komutlar ayrıca boru sarmalıyla beslenir; sebebi
     * aşağıda yazılı (sudo-rs dosya-sonunu iletmiyor).
     */
    private function run(RotationSession $session, string $command, string $failure, string $stdin = ''): void
    {
        $root = $session->username === 'root';

        /*
         * Girdi tüketen komut BORUYLA beslenir.
         *
         * Ubuntu 26.04'te (sudo-rs 0.2.13) sudo-rs
         * çocuğunu kendi uçbiriminde çalıştırıyor ve dosya-sonunu ona
         * İLETMİYOR; chpasswd girdiyi sonsuza kadar bekliyor. Klasik sudo'da
         * (AlmaLinux 9, sudo 1.9.5) bu sorun yok, ama iki dağıtım için iki
         * ayrı yol tutmak yerine tek yol: kabuk TEK SATIR okur ve komuta
         * boruyla verir; borunun kapanması girdinin bittiğini söyler.
         */
        if ($stdin !== '') {
            $command = 'sh -c '.escapeshellarg('read -r eys_satir; printf "%s\n" "$eys_satir" | '.$command);
        }

        /*
         * ZİNCİRİN TAMAMI sudo ALTINDA.
         *
         * "sudo -n useradd … && (usermod …)" yazıldığında sudo yalnızca İLK
         * komutu yetkili çalıştırıyordu; && sonrası kullanıcının kendi
         * yetkisiyle koşup reddediliyordu. Root olmayan hesapla kurtarma
         * hesabı açılıyor ama gruba eklenemiyor, tur
         * "kod 1" ile düşüyor ve makinede yarım hesap kalıyordu. Root
         * yolunda sudo eklenmediği için görünmüyordu. Girdi alan komut zaten
         * tek kabukta (yukarıda); diğerleri burada sarılır.
         */
        $yukseltilen = $stdin !== '' ? $command : 'sh -c '.escapeshellarg($command);

        $output = $root
            ? $this->execute($session, $command, $failure, $stdin)
            : $this->execute(
                $session,
                'sudo -n '.$yukseltilen,
                $failure,
                $stdin,
                /* Önce kimlik doğrulanır; sudo girdiye o aşamada dokunur. */
                $this->sudoPrelude(),
                $session->password."\n",
            );

        /*
         * Asıl karar çıkış kodundadır (bkz. ConnectsOverSsh::execute).
         * Buradaki metin kontrolü İKİNCİ bir ağdır: sudo bazı hâllerde
         * parolayı reddedip yine de sıfır dönebiliyor.
         */
        $lower = strtolower($output);

        if (str_contains($lower, 'sorry, try again')
            || str_contains($lower, 'a password is required')) {
            throw new RotationException($failure.' Yükseltme parolası kabul edilmedi.');
        }
    }

    /** Kabuğa geçen ad yalnızca güvenli karakterler taşır. */
    private function guardName(string $username): void
    {
        if (preg_match('/^[a-z_][a-z0-9_-]{0,30}$/', $username) !== 1) {
            throw new RotationException('Hesap adı kabuğa gönderilemeyecek bir karakter içeriyor.');
        }
    }
}
