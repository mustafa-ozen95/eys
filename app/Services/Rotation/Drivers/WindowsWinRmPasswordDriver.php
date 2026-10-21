<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\Drivers\Concerns\ManagesWindowsAccounts;
use App\Services\Rotation\Drivers\Concerns\RunsOverWinRm;

/**
 * Windows yerel hesapları — WinRM üzerinden.
 *
 * `windows` sürücüsüyle AYNI komutları çalıştırır (bkz. ManagesWindowsAccounts);
 * tek farkı taşıma. Makinede OpenSSH kurulu değilse tek yol budur ve WinRM
 * Windows'ta zaten açık gelir: hedef makineye hiçbir şey kurulmaz, hiçbir
 * ayar değiştirilmez.
 *
 * Windows Server 2019'da (build 17763, WinRM 5985, AllowUnencrypted=false)
 * NTLM ile kimlik doğrulanıp mesaj şifrelemesi
 * açıkken kabuk açılıyor, PowerShell çalışıyor, parola standart girdiden
 * geçiyor.
 */
class WindowsWinRmPasswordDriver implements PasswordDriver
{
    use ManagesWindowsAccounts;
    use RunsOverWinRm;

    public const KEY = 'winrm';

    /**
     * Kurtarma hesabı bu yolda VARSAYILAN OLARAK KAPALIDIR.
     *
     * Windows Server 2019'da yeni açılan yerel yönetici
     * hesabı makinede oluşuyor ve Administrators grubuna giriyor, ama
     * UZAKTAN yönetici yetkisi alamıyor — Windows, yerleşik Administrator
     * dışındaki yerel hesapların uzak jetonunu kısıtlıyor
     * (LocalAccountTokenFilterPolicy tanımsızken). Kurtarma hesabı açılıp
     * kanıt aşamasında düşer ve tur boşuna iptal olur.
     *
     * O kaydı 1 yapan kurulumlarda ayar açılabilir: ROTATION_WINRM_RECOVERY=true.
     */
    public function supportsRecovery(): bool
    {
        return (bool) config('rotation.winrm.recovery', false);
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
}
