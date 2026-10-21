<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\ConnectsOverSsh;
use App\Services\Rotation\Drivers\Concerns\SelectsMachineSshRecord;
use App\Services\Rotation\Drivers\Concerns\VerifiesPleskLogin;
use App\Services\Rotation\MachineAccount;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * Plesk yönetici (admin) parolasını döndürür.
 *
 * İKİ AYRI KİMLİK var ve karıştırılmamalı:
 *  - Değiştirilen: panelin `admin` hesabı (kaydın kendi parolası).
 *  - Değiştiren: makinenin SSH kimliği (kaydın "döndürme hesabı" alanı).
 * Bu yüzden kurtarma hesabına gerek yoktur: kapı zaten ayrı bir kanaldan
 * açık kalıyor, tıpkı API anahtarıyla çalışan sürücülerde olduğu gibi.
 *
 * PAROLA KOMUT SATIRINA YAZILMAZ. `plesk bin admin --set-password -passwd`
 * parolayı argüman olarak alır ve hedef makinenin süreç listesine düşürür —
 * paylaşımlı bir barındırma sunucusunda bu, oradaki herkese göstermek
 * demektir. Plesk'in kendi aracı `ch_admin_passwd` parolayı ORTAM
 * DEĞİŞKENİNDEN okur; değişken de standart girdiden doldurulur, yani parola
 * ne argümanda ne diskte durur.
 *
 * Plesk Obsidian 18.0.x'te giriş ucu doğru parolada 303 (yönlendirme),
 * yanlış parolada 200 (giriş sayfasını yeniden basar) döndürüyor. Doğrulama bu ayrımı kullanır.
 */
class PleskPasswordDriver implements NeedsMachineAccount, PasswordDriver
{
    /*
     * İKİ TAŞIMA, İKİ `verify`. SSH taşıması "bağlanabiliyor muyum" diye
     * sorar; burada doğru soru "PANELE girilebiliyor mu" ve cevabı Plesk'in
     * kendi giriş ucu veriyor. Çakışma açıkça çözülür ki hangisinin
     * kullanıldığı okunurken görünsün.
     */
    use ConnectsOverSsh, VerifiesPleskLogin {
        VerifiesPleskLogin::verify insteadof ConnectsOverSsh;
    }
    use SelectsMachineSshRecord;

    public const KEY = 'plesk';

    /** Parolayı ortam değişkeninden okuyan Plesk aracı. */
    private const TOOL = '/usr/local/psa/admin/sbin/ch_admin_passwd';

    public function __construct(?int $timeout = null)
    {
        $this->timeout = $timeout ?? (int) config('rotation.ssh_timeout', 30);
    }

    protected function defaultPort(): int
    {
        return (int) config('rotation.plesk.ssh_port', 22);
    }

    /**
     * Kaydın portu PANELİN portudur (8443); SSH oraya bağlanmaz.
     *
     * Kaydın portu kullanılsaydı sürücü 8443'e SSH kurmaya çalışır ve
     * "bağlantı kapandı" derdi.
     *
     * SEÇİLEN SSH KAYDININ portu ise tam olarak aranan şeydir: makinenin
     * SSH'ı 22 dışında bir portta (ör. 2222) olduğunda varsayılan 22'ye
     * giden tur "Connection refused" ile düşerdi.
     */
    protected function connectPort(Credential $credential): int
    {
        return app(MachineAccount::class)->port($credential) ?? $this->defaultPort();
    }

    /**
     * Kurtarma hesabı YOK.
     *
     * Parolasını değiştirdiğimiz hesapla bağlanmıyoruz; bağlantı makinenin
     * SSH kimliğiyle. Bir şey ters giderse kapı yine açık, denenen parola da
     * kasada duruyor.
     */
    public function supportsRecovery(): bool
    {
        return false;
    }

    public function canVerify(): bool
    {
        return true;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) => 'Adres tanımlı değil.',
            $credential->username !== 'admin' => 'Bu sürücü yalnızca Plesk yönetici hesabını (admin) günceller.',
            blank($credential->password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            default => app(MachineAccount::class)->eksikSebep($credential),
        };
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        throw new RotationException('Plesk sürücüsü kurtarma hesabı açmaz.');
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        throw new RotationException('Plesk sürücüsü kurtarma hesabı açmaz.');
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        if ($username !== 'admin') {
            throw new RotationException('Plesk sürücüsü yalnızca admin hesabını değiştirir.');
        }

        /*
         * Parola girdiden okunur ve ortam değişkenine konur; ne argümanda ne
         * dosyada görünür. `read -r` tam bir satır alır, araç da onu okur.
         */
        $this->run(
            $session,
            'read -r PSA_PASSWORD; export PSA_PASSWORD; '.self::TOOL,
            'Plesk yönetici parolası değiştirilemedi.',
            $newPassword."\n",
        );
    }

    /** SSH komutu; root değilse sudo ile (bkz. ConnectsOverSsh). */
    private function run(RotationSession $session, string $command, string $failure, string $stdin = ''): void
    {
        $root = $session->username === 'root';

        $this->execute(
            $session,
            $root ? $command : 'sudo -n sh -c '.escapeshellarg($command),
            $failure,
            $stdin,
            $root ? '' : $this->sudoPrelude(),
            $root ? '' : $session->password."\n",
        );
    }
}
