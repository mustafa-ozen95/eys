<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\Remote\RemoteTarget;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Terminal süreci için kurulan komut satırı.
 *
 * KULLANICI ADI KONAĞA YAPIŞTIRILMAZ. Tek argüman ("kullanici@konak")
 * verilirse ssh o argümanı, tire ile başlıyorsa, bir SEÇENEK olarak
 * ayrıştırır; geriye konak kalmadığı için ssh kullanım metnini basıp çıkar
 * ve bağlantı anlaşılmaz bir hatayla düşer.
 */
class TtydKomutuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function komut(string $kullanici, string $konak = '10.0.0.5'): array
    {
        $hedef = new RemoteTarget(
            subject: Credential::factory()->create(),
            label: 'Sınama',
            host: $konak,
            protocol: AccessType::Ssh,
            port: 22,
            username: $kullanici,
            password: 'parola',
        );

        $metot = new ReflectionMethod(TtydManager::class, 'command');

        return $metot->invoke(app(TtydManager::class), $hedef, 7500);
    }

    #[Test]
    public function kullanici_adi_ayri_argumanda_gecer(): void
    {
        $komut = $this->komut('root');

        $yer = array_search('-l', $komut, true);

        $this->assertNotFalse($yer, 'Kullanıcı adı "-l" ile geçmeli.');
        $this->assertSame('root', $komut[$yer + 1]);
        $this->assertSame('10.0.0.5', $komut[array_key_last($komut)], 'Konak son argüman olmalı.');

        /* Yapıştırılmış biçim hiçbir argümanda kalmamalı. */
        $this->assertNotContains('root@10.0.0.5', $komut);
    }

    /**
     * ASIL SINAMA BU: tire ile başlayan kullanıcı adı.
     *
     * Eski biçimde bu argüman ssh'a bir seçenek olarak geçiyordu. Artık
     * "-l"nin DEĞERİ olduğu için ssh onu seçenek saymaz.
     */
    #[Test]
    public function tireyle_baslayan_kullanici_adi_secenege_donusmez(): void
    {
        $ad = '-oProxyCommand=yes';
        $komut = $this->komut($ad);

        $yer = array_search('-l', $komut, true);

        $this->assertNotFalse($yer);
        $this->assertSame($ad, $komut[$yer + 1], 'Ad "-l" değerinde durmalı.');

        /* Konak hâlâ son argüman: ssh bağlanacak bir yer bulabiliyor. */
        $this->assertSame('10.0.0.5', $komut[array_key_last($komut)]);
    }
}
