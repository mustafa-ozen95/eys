<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Veritabani\BaglantiDosyasi;
use App\Services\Veritabani\UzakVeritabani;
use PDO;
use RuntimeException;
use Tests\TestCase;

/**
 * Ayarlar → Veritabanı taşımasının kaynağı: kurulumun verdiği veritabanı.
 *
 * Asıl kurallar: kaynak her zaman ortamın veritabanıdır (compose'taki db
 * servisi ya da DB_HOST); panelden zaten taşınmış bir kurulum ikinci kez
 * taşınmaz; hedef şu anki veritabanının kendisiyse hedefe hiç bağlanılmaz.
 */
class TasimaKaynagiTest extends TestCase
{
    /** @param  array<string, mixed>  $bilgi */
    private function servis(array $bilgi): UzakVeritabani
    {
        return new class(new BaglantiDosyasi(app('encrypter'), sys_get_temp_dir().'/yok-'.bin2hex(random_bytes(4))), $bilgi) extends UzakVeritabani
        {
            /** @param  array<string, mixed>  $sahteBilgi */
            public function __construct(BaglantiDosyasi $dosya, private array $sahteBilgi)
            {
                parent::__construct($dosya);
            }

            public function bilgi(): array
            {
                return [...['kaynak' => 'ortam', 'host' => 'db', 'port' => '3306', 'database' => 'panel', 'username' => 'panel',
                    'surum' => '11.4.5-MariaDB', 'boyut' => 1, 'tablo' => 10, 'ozel' => 0], ...$this->sahteBilgi];
            }

            protected function baglan(array $h): PDO
            {
                throw new RuntimeException('sınamada hedefe bağlanılmaz: '.$h['host']);
            }
        };
    }

    /** @return array<string, array{tamam: bool, ayrinti: string}> */
    private function maddeler(UzakVeritabani $v, array $hedef): array
    {
        return collect($v->sina([...['host' => 'uzak.ornek.test', 'port' => 3306, 'database' => 'panel', 'username' => 'panel', 'password' => 'x'], ...$hedef]))
            ->keyBy('ad')->all();
    }

    public function test_kaynak_kurulumun_veritabani_ise_kopya_oradan_alinir(): void
    {
        $m = $this->maddeler($this->servis([]), []);

        $this->assertTrue($m['Panel kurulumun veritabanında']['tamam']);
        $this->assertStringContainsString('db:3306/panel', $m['Panel kurulumun veritabanında']['ayrinti']);
        $this->assertTrue($m['Hedef başka bir veritabanı']['tamam']);
        $this->assertFalse($m['Bağlantı']['tamam'], 'hedefe bağlanma denenir (burada sahte, düşer)');
    }

    public function test_panelden_tasinmis_kurulum_ikinci_kez_tasinmaz(): void
    {
        $m = $this->maddeler($this->servis(['kaynak' => 'panel', 'host' => 'uzak1.ornek.test']), []);

        $this->assertFalse($m['Panel kurulumun veritabanında']['tamam']);
        $this->assertFalse(UzakVeritabani::hepsiTamam(array_values($m)));
    }

    public function test_hedef_su_anki_veritabani_ise_hedefe_baglanilmaz(): void
    {
        $m = $this->maddeler($this->servis([]), ['host' => 'DB', 'port' => 3306, 'database' => 'panel']);

        $this->assertFalse($m['Hedef başka bir veritabanı']['tamam']);
        $this->assertArrayNotHasKey('Bağlantı', $m);
    }
}
