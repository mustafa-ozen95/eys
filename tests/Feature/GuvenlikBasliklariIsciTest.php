<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * İçerik güvenliği politikası İŞÇİYE izin verir.
 *
 * `worker-src` yazılmadığında tarayıcı `script-src`e düşer ve blob'dan
 * kurulan işçi engellenir. Engel SESSİZ: `new Worker` hata fırlatmaz, işçi
 * yalnızca hiç çalışmaz ve ona bağlı zamanlayıcılar sessizce durur.
 */
class GuvenlikBasliklariIsciTest extends TestCase
{
    public function test_csp_blob_isciye_izin_verir(): void
    {
        $csp = (string) config('security.headers.Content-Security-Policy');

        $this->assertStringContainsString("worker-src 'self' blob:", $csp);
    }

    /** Politika hâlâ dar: dışarıdan betik çalıştırma yolu açılmadı. */
    public function test_script_src_hala_kendi_kokeninde(): void
    {
        $csp = (string) config('security.headers.Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString("script-src 'self' blob:", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
    }
}
