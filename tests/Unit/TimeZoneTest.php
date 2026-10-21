<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Panel ve veritabanı oturumu konteynerin saatinde.
 *
 * Asıl kural: uzak ya da yönetilen bir veritabanı UTC'de çalışsa bile oturum
 * iç veritabanıyla aynı dilimde açılır; yoksa TIMESTAMP sütunları taşımadan
 * sonra saat farkı kadar kaymış okunur.
 */
class TimeZoneTest extends TestCase
{
    private const BASLATICI = __DIR__.'/../../docker/rootfs/usr/local/bin/eys-entrypoint';

    /** @var array<string, string|false> */
    private array $onceki = [];

    protected function tearDown(): void
    {
        foreach ($this->onceki as $ad => $deger) {
            unset($_ENV[$ad], $_SERVER[$ad]);
            if ($deger !== false) {
                $_ENV[$ad] = $_SERVER[$ad] = $deger;
            }
        }

        parent::tearDown();
    }

    private function ortam(string $ad, string $deger): void
    {
        $this->onceki[$ad] = $_ENV[$ad] ?? false;
        $_ENV[$ad] = $_SERVER[$ad] = $deger;
    }

    public function test_panel_ve_oturum_dilimi_ortamdan_okunur(): void
    {
        $this->ortam('APP_TIMEZONE', 'Europe/Istanbul');
        $this->ortam('DB_TIMEZONE', '+03:00');

        $this->assertSame('Europe/Istanbul', (require __DIR__.'/../../config/app.php')['timezone']);

        $baglantilar = (require __DIR__.'/../../config/database.php')['connections'];
        $this->assertSame('+03:00', $baglantilar['mysql']['timezone']);
        $this->assertSame('+03:00', $baglantilar['mariadb']['timezone']);
    }

    public function test_baslatici_oturum_dilimini_konteynerin_saatinden_verir(): void
    {
        $baslatici = (string) file_get_contents(self::BASLATICI);

        $this->assertStringContainsString(': "${DB_TIMEZONE:=$(date +%:z)}"', $baslatici);
        $this->assertStringContainsString('DB_USERNAME DB_PASSWORD DB_TIMEZONE \\', $baslatici);
    }
}
