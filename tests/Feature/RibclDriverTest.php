<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\RibclPasswordDriver;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RIBCL sürücüsü — iLO 3 ve sonrası.
 *
 * Canlı bir iLO'ya BAĞLANILMAZ; HPE kılavuzundaki yanıt biçimleri taklit
 * edilir (bkz. .ai/rules/calisma.md).
 */
class RibclDriverTest extends TestCase
{
    use RefreshDatabase;

    /** iLO'nun gerçek yanıtı: birden çok RIBCL parçası arka arkaya. */
    private const OK = <<<'XML'
        <?xml version="1.0"?>
        <RIBCL VERSION="2.22"><RESPONSE STATUS="0x0000" MESSAGE='No error' /></RIBCL>
        <?xml version="1.0"?>
        <RIBCL VERSION="2.22"><RESPONSE STATUS="0x0000" MESSAGE='No error' /></RIBCL>
        XML;

    private const YETKI_HATASI = <<<'XML'
        <?xml version="1.0"?>
        <RIBCL VERSION="2.22"><RESPONSE STATUS="0x0000" MESSAGE='No error' /></RIBCL>
        <?xml version="1.0"?>
        <RIBCL VERSION="2.22"><RESPONSE STATUS="0x0023"
          MESSAGE='User does NOT have correct privilege for action. ADMIN_PRIV required.' /></RIBCL>
        XML;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Bmc,
            'rotation_driver' => RibclPasswordDriver::KEY,
            'host' => '198.51.100.31',
            'port' => 443,
            'url' => null,
            'username' => 'Administrator',
            'password' => 'eski-parola',
            ...$ustune,
        ]);
    }

    #[Test]
    public function parola_degisimi_kilavuzdaki_bicimde_gonderilir(): void
    {
        Http::fake(['*' => Http::response(self::OK, 200)]);

        $driver = new RibclPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->changePassword($session, 'Administrator', 'yeni-parola');

        Http::assertSent(function ($r) {
            if (! str_contains($r->body(), 'MOD_USER')) {
                return false;
            }

            /* HPE kılavuzundaki tam biçim; USER_INFO MODE="write" şart. */
            return str_ends_with($r->url(), '/ribcl')
                && str_contains($r->body(), '<USER_INFO MODE="write">')
                && str_contains($r->body(), '<MOD_USER USER_LOGIN="Administrator">')
                && str_contains($r->body(), '<PASSWORD value="yeni-parola"/>');
        });
    }

    #[Test]
    public function kurtarma_hesabi_tum_ayricaliklarla_acilir(): void
    {
        Http::fake(['*' => Http::response(self::OK, 200)]);

        $driver = new RibclPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'gecici');

        /* Yetkisiz bir iLO hesabı parola değiştiremez. */
        Http::assertSent(fn ($r) => str_contains($r->body(), '<ADD_USER USER_NAME="eys-kurtarma-abc123"')
            && str_contains($r->body(), '<ADMIN_PRIV value="Y"/>')
            && str_contains($r->body(), '<CONFIG_ILO_PRIV value="Y"/>'));
    }

    #[Test]
    public function hesap_silme_kilavuzdaki_bicimde_gonderilir(): void
    {
        Http::fake(['*' => Http::response(self::OK, 200)]);

        $driver = new RibclPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->deleteRecovery($session, 'eys-kurtarma-abc123');

        Http::assertSent(fn ($r) => str_contains($r->body(), '<DELETE_USER USER_LOGIN="eys-kurtarma-abc123"/>'));
    }

    #[Test]
    public function http_200_dondurse_bile_ribcl_hatasi_yakalanir(): void
    {
        /*
         * iLO başarısız komutta da HTTP 200 döner. Yalnızca duruma bakan bir
         * sürücü, olmamış bir parola değişimini başarılı sayardı.
         */
        Http::fake(['*' => Http::response(self::YETKI_HATASI, 200)]);

        $driver = new RibclPasswordDriver;

        try {
            $driver->open($this->kayit(), 'Administrator', 'eski-parola');
            $this->fail('İstisna atmalıydı.');
        } catch (RotationException $e) {
            $this->assertStringContainsString('0x0023', $e->getMessage());
            $this->assertStringContainsString('ADMIN_PRIV', $e->getMessage());
        }
    }

    #[Test]
    public function durum_satiri_olmayan_yanit_basari_sayilmaz(): void
    {
        /* Sessiz geçmek, hiçbir şey yapılmadığını başarı sanmaktır. */
        Http::fake(['*' => Http::response('<html>bir sey ters gitti</html>', 200)]);

        $this->expectException(RotationException::class);

        (new RibclPasswordDriver)->open($this->kayit(), 'Administrator', 'eski-parola');
    }

    #[Test]
    public function parolada_xml_karakterleri_kacisla_gonderilir(): void
    {
        Http::fake(['*' => Http::response(self::OK, 200)]);

        $driver = new RibclPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->changePassword($session, 'Administrator', 'a"b&c<d');

        /* Kaçış olmasaydı tırnak öznitelik değerini kapatır, betik bozulurdu. */
        Http::assertSent(fn ($r) => str_contains($r->body(), 'a&quot;b&amp;c&lt;d'));
    }

    #[Test]
    public function yanlis_kimlikte_dogrulama_false_doner(): void
    {
        Http::fake(['*' => Http::response(
            '<RIBCL VERSION="2.22"><RESPONSE STATUS="0x0001" MESSAGE=\'Login failed\' /></RIBCL>', 200,
        )]);

        $this->assertFalse((new RibclPasswordDriver)->verify($this->kayit(), 'Administrator', 'yanlis'));
    }
}
