<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers\Concerns;

use App\Models\Credential;
use App\Services\DeviceAddress;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Plesk doğrulaması — PANELE GİREREK, makineye bağlanarak değil.
 *
 * Değişen şey panelin parolası; makineye (SSH ya da WinRM) bağlanabilmek onun
 * çalıştığını kanıtlamaz. Doğrulama iki sürücüde de aynı: Linux'ta da
 * Windows'ta da Plesk'in kendi giriş ucu kullanılıyor.
 *
 * Plesk Obsidian 18.0.x'te (Linux ve Windows) giriş ucu doğru parolada 303
 * (yönlendirme), yanlış parolada 200 (giriş sayfasını yeniden basar)
 * döndürüyor.
 */
trait VerifiesPleskLogin
{
    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $yanit = Http::withOptions(['verify' => false, 'allow_redirects' => false])
                ->timeout($this->timeout)
                ->asForm()
                ->post(DeviceAddress::for($credential, assumedPort: 8443).'/login_up.php', [
                    'login_name' => $username,
                    'passwd' => $password,
                ]);
        } catch (Throwable) {
            return false;
        }

        /* Doğru parola yönlendirir; yanlış parola giriş sayfasını yeniden basar. */
        return $yanit->status() === 303;
    }
}
