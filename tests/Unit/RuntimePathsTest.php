<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Çalışma yollarının İngilizce adlara geçişi (dc-paths): eski hacimdeki adlar
 * açılışta taşınır, ikisi birden varsa kimseye dokunulmaz.
 */
class RuntimePathsTest extends TestCase
{
    private const BETIK = __DIR__.'/../../docker/rootfs/usr/local/bin/dc-paths';

    private const CIFTLER = [
        'veritabani=database', 'database/baglanti.json=database/connection.json', 'database/durum.json=database/state.json',
        'guncelleme=update', 'update/durum.json=update/state.json', 'ilk-parola.txt=initial-password.txt',
    ];

    private string $kok;

    protected function setUp(): void
    {
        $this->kok = sys_get_temp_dir().'/paths-'.bin2hex(random_bytes(4));
        mkdir($this->kok, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->kok));
    }

    private function calistir(): array
    {
        $cmd = array_merge(['sh', self::BETIK, $this->kok], self::CIFTLER);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $borular);
        $cikti = stream_get_contents($borular[1]).stream_get_contents($borular[2]);
        $kod = proc_close($p);

        return [$kod, $cikti];
    }

    public function test_eski_adlar_yeni_adlara_tasinir_icerik_korunur(): void
    {
        mkdir($this->kok.'/veritabani');
        file_put_contents($this->kok.'/veritabani/baglanti.json', 'SIFRELI');
        file_put_contents($this->kok.'/veritabani/durum.json', '{"durum":"x"}');
        mkdir($this->kok.'/guncelleme');
        file_put_contents($this->kok.'/guncelleme/durum.json', '{"durum":"guncel"}');
        file_put_contents($this->kok.'/ilk-parola.txt', "ad\nparola\n");

        [$kod] = $this->calistir();

        $this->assertSame(0, $kod);
        $this->assertSame('SIFRELI', file_get_contents($this->kok.'/database/connection.json'));
        $this->assertSame('{"durum":"x"}', file_get_contents($this->kok.'/database/state.json'));
        $this->assertSame('{"durum":"guncel"}', file_get_contents($this->kok.'/update/state.json'));
        $this->assertSame("ad\nparola\n", file_get_contents($this->kok.'/initial-password.txt'));
        $this->assertFileDoesNotExist($this->kok.'/veritabani');
        $this->assertFileDoesNotExist($this->kok.'/guncelleme');
        $this->assertFileDoesNotExist($this->kok.'/ilk-parola.txt');
    }

    public function test_ikisi_de_varsa_dokunulmaz_uyari_yazilir(): void
    {
        mkdir($this->kok.'/veritabani');
        file_put_contents($this->kok.'/veritabani/baglanti.json', 'ESKI');
        mkdir($this->kok.'/database');
        file_put_contents($this->kok.'/database/connection.json', 'YENI');

        [$kod, $cikti] = $this->calistir();

        $this->assertSame(0, $kod);
        $this->assertStringContainsString('UYARI', $cikti);
        $this->assertSame('ESKI', file_get_contents($this->kok.'/veritabani/baglanti.json'));
        $this->assertSame('YENI', file_get_contents($this->kok.'/database/connection.json'));
    }

    public function test_hicbiri_yoksa_sessizce_gecer(): void
    {
        [$kod, $cikti] = $this->calistir();

        $this->assertSame(0, $kod);
        $this->assertSame('', $cikti);
        $this->assertSame([], array_values(array_diff(scandir($this->kok), ['.', '..'])));
    }

    public function test_ikinci_calistirma_bir_sey_degistirmez(): void
    {
        mkdir($this->kok.'/veritabani');
        file_put_contents($this->kok.'/veritabani/durum.json', 'X');

        $this->calistir();
        [$kod, $cikti] = $this->calistir();

        $this->assertSame(0, $kod);
        $this->assertSame('', $cikti);
        $this->assertSame('X', file_get_contents($this->kok.'/database/state.json'));
    }
}
