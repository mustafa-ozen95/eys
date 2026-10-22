<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Konteyner günlüğüne sır basılmaz.
 *
 * Üretilen APP_KEY kasanın bütün parolalarını açar, ilk yönetici parolası
 * panelin kapısıdır; ikisi de zaten 0600 dosyada duruyor. Günlüğü okuyabilen
 * (docker logs, günlük toplayıcılar) bunları da okumamalı: günlüğe yalnızca
 * dosyanın yeri yazılır.
 */
class LoginScriptSecretLeakproofTest extends TestCase
{
    #[Test]
    public function giris_betigi_anahtari_ve_ilk_parolayi_gunluge_yazmaz(): void
    {
        $betik = (string) file_get_contents(base_path('docker/rootfs/usr/local/bin/eys-entrypoint'));

        /* Günlüğe giden her satır (bilgi/uyari/echo/printf stdout) sır değişkenini taşımamalı. */
        foreach (preg_split('/\R/', $betik) as $satir) {
            if (preg_match('/^\s*(uyari|bilgi|hata|echo)\b/', $satir) === 1) {
                $this->assertStringNotContainsString('${APP_KEY}', $satir, $satir);
                $this->assertStringNotContainsString('${ADMIN_PASSWORD}', $satir, $satir);
            }
        }

        /* Sır dosyaya yazılmaya devam ediyor ve kurulum betikleri bu dosyalara bakıyor. */
        $this->assertStringContainsString('> "${ANAHTAR_DOSYASI}"', $betik);
        $this->assertStringContainsString('> "${ILK_PAROLA}"', $betik);
        $this->assertStringContainsString('chmod 0600 "${ANAHTAR_DOSYASI}"', $betik);
        $this->assertStringContainsString('chmod 0600 "${ILK_PAROLA}"', $betik);
        $this->assertStringContainsString('storage/app/app-key.txt', $betik);
    }

    #[Test]
    public function portal_penceresi_paneli_opener_ile_gormez(): void
    {
        $js = (string) file_get_contents(base_path('resources/js/components/portal.js'));

        $this->assertStringContainsString('popup.opener = null', $js);
    }
}
