<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

/**
 * WinRM — KURTARMA HESAPLI sıra.
 *
 * `winrm` ile aynı sürücüdür; tek farkı geçici hesap açmasıdır. İkisi ayrı
 * anahtar olarak duruyor çünkü bu, makineden makineye değişen bir yetenek:
 *
 * Windows, yerleşik Administrator dışındaki yerel hesaplara UZAKTAN yönetici
 * jetonu vermez. `LocalAccountTokenFilterPolicy=1` ayarlı makinelerde verir ve
 * geçici hesap çalışır; ayarsız makinelerde hesap açılır ama içeri giremez,
 * tur da boşuna iptal olur (Windows Server 2019'da görüldü).
 *
 * Bu yüzden karar kaydın kendisinde: hangi makinede hangi sıranın işlediğini
 * global bir anahtar değil, kaydın sürücü alanı söyler.
 */
class WindowsWinRmRecoveryPasswordDriver extends WindowsWinRmPasswordDriver
{
    public const KEY = 'winrm_kurtarmali';

    public function supportsRecovery(): bool
    {
        return true;
    }
}
