<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\DogrularPleskGirisi;
use App\Services\Rotation\Drivers\Concerns\RunsOverWinRm;
use App\Services\Rotation\MakineHesabi;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * WINDOWS ÜZERİNDEKİ Plesk yönetici (admin) parolasını döndürür.
 *
 * Linux'taki kardeşiyle (bkz. PleskPasswordDriver) aynı işi yapar, tek farkı
 * TAŞIMA ve KOMUT: orada SSH ve `ch_admin_passwd`, burada WinRM ve Plesk'in
 * kendi `plesk.exe` aracı.
 *
 * NEDEN AYRI BİR SÜRÜCÜ: `plesk` sürücüsü makineye SSH ile bağlanıyor ve
 * Linux'a özgü bir ikili çalıştırıyor. Windows'taki Plesk sunucusunda
 * bunların hiçbiri olmayabilir — SSH kapalı, WinRM (5985) açık. Böyle bir
 * kayıt `plesk` sürücüsüyle hiçbir zaman çalışamaz.
 *
 * İKİ AYRI KİMLİK var ve karıştırılmamalı:
 *  - Değiştirilen: panelin `admin` hesabı (kaydın kendi parolası).
 *  - Değiştiren: MAKİNENİN Windows yönetici hesabı — kasadan seçilir ve
 *    Linux'takinin aksine SSH değil RDP/uzak yönetim kaydıdır.
 * Bu yüzden kurtarma hesabına gerek yoktur: kapı zaten ayrı bir kanaldan
 * açık kalıyor.
 *
 * PAROLA KOMUT SATIRINA YAZILMAZ. Plesk'in kendi yardımı bunu açıkça
 * söylüyor (Windows üzerindeki Plesk'in yardım metni):
 *
 *   "For security reasons, we recommend that you pass non-encrypted passwords
 *    using the environment variable PSA_PASSWORD. To do so, specify an empty
 *    value in the command line for the argument(s) passwd (like ' -passwd "" ')
 *    and specify the password in the variable PSA_PASSWORD."
 *
 * Parola PowerShell'e WinRM KABUĞUNUN ORTAM DEĞİŞKENİNDEN geliyor, oradan
 * PSA_PASSWORD'e konuyor: ne betiğin içinde ne komut satırında görünüyor
 * (bkz. PowerShellGizli — kurulu pywinrm standart girdiyi desteklemiyor).
 *
 * ARAÇ YOLU ARANMAZ, PATH'TEN BULUNUR: `plesk.exe` kurulumla birlikte PATH'e
 * giriyor (ör. C:\Program Files (x86)\Plesk\ctl\plesk.exe). Sabit bir yol
 * yazmak, farklı sürücüye kurulmuş bir Plesk'te sessizce kırılırdı.
 */
class PleskWindowsPasswordDriver implements NeedsMachineAccount, PasswordDriver
{
    /*
     * İKİ TAŞIMA, İKİ `verify`. WinRM taşıması "bağlanabiliyor muyum" diye
     * sorar; burada doğru soru "PANELE girilebiliyor mu" ve cevabı Plesk'in
     * kendi giriş ucu veriyor.
     */
    use DogrularPleskGirisi, RunsOverWinRm {
        DogrularPleskGirisi::verify insteadof RunsOverWinRm;
    }

    public const KEY = 'plesk_windows';

    private int $timeout;

    public function __construct(?int $timeout = null)
    {
        $this->timeout = $timeout ?? (int) config('rotation.winrm.timeout', 60);
    }

    /** @return list<AccessType> */
    public function makineErisimTurleri(): array
    {
        /*
         * Windows makinede "yönetim kaydı" pratikte RDP kaydıdır; OpenSSH
         * kurulmuş makinelerde SSH kaydı da olabilir, o yüzden ikisi de
         * seçilebilir. Bağlantı her hâlükârda WinRM ile kurulur — seçilen
         * kayıt yalnızca KİMLİĞİ verir.
         */
        return [AccessType::Rdp, AccessType::Ssh];
    }

    /**
     * WinRM PORTU KAYITTAN GELMEZ.
     *
     * Kaydın portu panelin portudur (8443) ve WinRM oraya bağlanmaz. Seçilen
     * yönetim kaydının portu da işe yaramaz: o RDP portudur (ör. 3390).
     * Geriye WinRM'in kendi portu kalıyor.
     */
    protected function winRmPort(Credential $credential): int
    {
        return (int) config('rotation.winrm.port', 5985);
    }

    /**
     * Kurtarma hesabı YOK.
     *
     * Parolasını değiştirdiğimiz hesapla bağlanmıyoruz; bağlantı makinenin
     * Windows yöneticisiyle. Bir şey ters giderse kapı yine açık, denenen
     * parola da kasada duruyor.
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
            default => app(MakineHesabi::class)->eksikSebep($credential),
        };
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        throw new RotationException('Plesk (Windows) sürücüsü kurtarma hesabı açmaz.');
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        throw new RotationException('Plesk (Windows) sürücüsü kurtarma hesabı açmaz.');
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        if ($username !== 'admin') {
            throw new RotationException('Plesk sürücüsü yalnızca admin hesabını değiştirir.');
        }

        /*
         * `-passwd ""` + PSA_PASSWORD: Plesk'in kendi önerdiği yol.
         *
         * KOMUT cmd.exe ÜZERİNDEN ÇAĞRILIR. PowerShell 5.1 native bir
         * programa geçirilen BOŞ ARGÜMANI düşürüyor (PowerShell 5.1.20348):
         *
         *     & cmd.exe /c echo ""     → "ECHO is on."   (argüman düştü)
         *     & cmd.exe /c echo '""'   → ""              (yerinde)
         *
         * Yani `& plesk.exe ... -passwd ""` çağrısında Plesk `-passwd`'ü
         * DEĞERSİZ görüyor ve 1 ile dönüyor. cmd.exe komut
         * satırını kendisi ayrıştırıyor ve `-passwd ""` orada gerçekten boş
         * bir argüman; Plesk'in yardımındaki örnek de bu biçimde.
         *
         * ÇIKIŞ KODU AÇIKÇA TAŞINIR: dış program hatası PowerShell'de istisna
         * değildir; kod aktarılmasaydı başarısız bir tur başarılı sayılırdı.
         */
        $this->run($session, <<<'PS'
            $env:PSA_PASSWORD = {{EYS_GIZLI}}
            & cmd.exe /c 'plesk bin admin --set-password -passwd ""'
            $kod = $LASTEXITCODE
            $env:PSA_PASSWORD = $null
            if ($kod -ne 0) { exit $kod }
            PS, 'Plesk yönetici parolası değiştirilemedi.', $newPassword);
    }

    /**
     * PowerShell betiğini çalıştırır.
     *
     * Parola betiğe GİRMEZ: betikte yalnızca bir yer tutucu durur ve yerini
     * taşıma doldurur — burada WinRM kabuğunun ortam değişkeni
     * (bkz. PowerShellGizli). Kalıp ManagesWindowsAccounts ile aynı.
     */
    private function run(RotationSession $session, string $script, string $failure, ?string $gizli = null): void
    {
        $this->runPowerShell(
            $session,
            "\$ErrorActionPreference = 'Stop'\ntry {\n{$script}\n exit 0\n} catch { exit 1 }",
            $failure,
            $gizli ?? '',
        );
    }
}
