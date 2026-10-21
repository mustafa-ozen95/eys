<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Database\ConnectionFile;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Panelin veritabanı ayar dosyaları.
 *
 * Bu dosyayı başlatıcı uygulamayı ayağa kaldırmadan okuyor; bu yüzden test de
 * Laravel'siz: düz Encrypter ve geçici bir dizin.
 */
class ConnectionFileTest extends TestCase
{
    private string $dizin;

    private const BAGLANTI = [
        'host' => 'db.ornek.local',
        'port' => 3307,
        'database' => 'panel',
        'username' => 'panel_uzak',
        'password' => "p@ss \"w0rd\" #x \\ 'q'\tson",
    ];

    protected function setUp(): void
    {
        $this->dizin = sys_get_temp_dir().'/baglanti-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dizin.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dizin);
    }

    private function dosya(?string $anahtar = null): ConnectionFile
    {
        return new ConnectionFile(new Encrypter($anahtar ?? str_repeat('a', 32), 'AES-256-CBC'), $this->dizin);
    }

    public function test_baglanti_sifreli_yazilir_ve_geri_okunur(): void
    {
        $this->dosya()->baglantiYaz(self::BAGLANTI);

        $this->assertSame(self::BAGLANTI, $this->dosya()->baglanti());

        $ham = (string) file_get_contents($this->dizin.'/connection.json');
        $this->assertStringNotContainsString('w0rd', $ham);
        $this->assertStringNotContainsString('panel_uzak', $ham);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dizin.'/connection.json')), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dizin)), -4));
    }

    public function test_baska_anahtarla_okunamaz(): void
    {
        $this->dosya()->baglantiYaz(self::BAGLANTI);

        $this->expectException(RuntimeException::class);
        $this->dosya(str_repeat('b', 32))->baglanti();
    }

    public function test_dosya_yoksa_baglanti_yok(): void
    {
        $this->assertNull($this->dosya()->baglanti());
        $this->assertNull($this->dosya()->durum());
    }

    /** Başarısızlıkta parola diskte kalmaz: bir daha okunmayacak, ancak sızabilir. */
    public function test_basarisiz_asamasi_baglantiyi_siler(): void
    {
        $dosya = $this->dosya();
        $dosya->baglantiYaz(self::BAGLANTI);
        $dosya->asamaYaz('tasinacak', null, ['hedef' => 'panel_uzak@db.ornek.local:3307/panel', 'isteyen' => 'Yönetici']);
        $dosya->asamaYaz('basarisiz', 'Uzak veritabanı boş değil.');

        $this->assertFileDoesNotExist($this->dizin.'/connection.json');
        $this->assertSame('basarisiz', $dosya->asama());
        $this->assertSame('Uzak veritabanı boş değil.', $dosya->durum()['aciklama']);
        $this->assertSame('Yönetici', $dosya->durum()['isteyen'], 'Önceki alanlar korunmalı.');
    }

    public function test_yeni_istek_onceki_sonucu_tasimaz(): void
    {
        $dosya = $this->dosya();
        $dosya->asamaYaz('basarisiz', 'eski sebep', ['hedef' => 'eski']);
        $dosya->asamaYaz('tasinacak', null, ['hedef' => 'yeni']);

        $this->assertArrayNotHasKey('aciklama', $dosya->durum());
        $this->assertSame('yeni', $dosya->durum()['hedef']);
    }

    public function test_aciklamasiz_ilerleme_onceki_aciklamayi_korur(): void
    {
        $dosya = $this->dosya();
        $dosya->asamaYaz('tasinacak');
        $dosya->asamaYaz('deneniyor', '42 tablo, 900 satır kopyalandı; iki tarafta sayılar aynı.');
        $dosya->asamaYaz('etkin');

        $this->assertSame('etkin', $dosya->asama());
        $this->assertSame('42 tablo, 900 satır kopyalandı; iki tarafta sayılar aynı.', $dosya->durum()['aciklama']);
    }

    public function test_bilinmeyen_asama_yazilmaz(): void
    {
        $this->expectException(RuntimeException::class);
        $this->dosya()->asamaYaz('bitti');
    }

    public function test_bozuk_durum_dosyasi_yok_sayilir(): void
    {
        mkdir($this->dizin, 0700);
        file_put_contents($this->dizin.'/state.json', '{"durum":"uydurma"}');

        $this->assertNull($this->dosya()->durum());
        $this->assertNull($this->dosya()->asama());
    }

    /** Başlatıcı satırları base64'ten çözüp ortama alıyor; tırnaklı parola bozulmamalı. */
    public function test_ortam_satirlari_degeri_bozmadan_tasir(): void
    {
        $this->dosya()->baglantiYaz(self::BAGLANTI);

        $satirlar = [];
        foreach (array_filter(explode("\n", $this->dosya()->ortamSatirlari())) as $satir) {
            [$ad, $deger] = explode('=', $satir, 2);
            $satirlar[$ad] = base64_decode($deger, true);
        }

        $this->assertSame([
            'DB_HOST' => 'db.ornek.local',
            'DB_PORT' => '3307',
            'DB_DATABASE' => 'panel',
            'DB_USERNAME' => 'panel_uzak',
            'DB_PASSWORD' => self::BAGLANTI['password'],
        ], $satirlar);
    }

    public function test_istemci_ayari_parolayi_tirnaklar(): void
    {
        $this->dosya()->baglantiYaz(self::BAGLANTI);

        $this->assertSame(
            "[client]\nhost=\"db.ornek.local\"\nport=3307\nuser=\"panel_uzak\"\npassword=\"p@ss \\\"w0rd\\\" #x \\\\ 'q'\\tson\"\n",
            $this->dosya()->istemciAyari(),
        );
    }

    public function test_yeniden_baslatma_istegi_dosya_birakir(): void
    {
        $this->assertTrue($this->dosya()->yenidenBaslatIste());
        $this->assertFileExists($this->dizin.'/restart');
    }

    public function test_temizle_yalnizca_bilinen_alanlari_alir(): void
    {
        $this->assertSame(
            ['host' => 'db', 'port' => 3306, 'database' => 'panel', 'username' => 'u', 'password' => ' p '],
            ConnectionFile::temizle(['host' => ' db ', 'port' => '', 'database' => 'panel', 'username' => 'u', 'password' => ' p ', 'fazla' => 'x']),
        );
    }
}
