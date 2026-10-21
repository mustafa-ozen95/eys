<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\ConnectsOverSsh;
use App\Services\Rotation\Drivers\Concerns\ManagesWindowsAccounts;
use App\Services\Rotation\PowerShellSecret;
use App\Services\Rotation\RotationSession;

/**
 * Windows yerel hesaplarını OpenSSH Server üzerinden yönetir.
 *
 * Taşıma Linux'takiyle AYNI (bkz. ConnectsOverSsh); değişen yalnızca
 * komutlar. WinRM yerine SSH seçilmesinin sebebi bu: iki platform tek
 * bağlantı katmanı paylaşıyor ve tek yerde bakılıyor. Karşılığında hedef
 * makinelerde OpenSSH Server özelliğinin açık olması gerekir.
 *
 * İki ayrıntı bu sürücünün tamamını belirliyor:
 *
 *  1. Parola KOMUT SATIRINA YAZILMAZ. `net user kullanici parola` en bilinen
 *     yol ama parolayı süreç listesine düşürür. Bunun yerine PowerShell
 *     parolayı standart girdiden okur.
 *
 *  1b. Cmdlet'ler Microsoft.PowerShell.LocalAccounts modülünden gelir
 *      (Windows 10 / Server 2016 ve sonrası, PowerShell 5.1 ile hazır).
 *      Microsoft'un notu: bu modül 64-bit sistemde 32-bit PowerShell'de
 *      YOKTUR. OpenSSH varsayılan olarak 64-bit kabuğu açtığı için sorun
 *      çıkmaz; kabuk elle değiştirilmişse komut "cmdlet bulunamadı" der ve
 *      tur güvenli biçimde düşer.
 *
 *  2. Yönetici grubunun adı TÜRKÇE Windows'ta "Administrators" DEĞİLDİR.
 *     Grup, yerelleştirmeden etkilenmeyen tanınmış kimliğiyle (S-1-5-32-544)
 *     bulunur; adına göre aramak Türkçe kurulumda sessizce başarısız olurdu.
 */
class WindowsPasswordDriver implements PasswordDriver
{
    use ConnectsOverSsh;
    use ManagesWindowsAccounts;

    public const KEY = 'windows';

    /** Zaman aşımı ayardan gelir (bkz. SshPasswordDriver). */
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

    protected function runPowerShell(
        RotationSession $session,
        string $script,
        string $failure,
        string $gizli,
    ): void {
        /* SSH'ta sır standart girdiden gider; bu yol yıllardır çalışıyor. */
        $encoded = PowerShellSecret::kodla(PowerShellSecret::stdinIle($script));

        $this->execute(
            $session,
            "powershell.exe -NonInteractive -NoProfile -EncodedCommand {$encoded}",
            $failure,
            $gizli === '' ? '' : $gizli."\n",
        );
    }
}
