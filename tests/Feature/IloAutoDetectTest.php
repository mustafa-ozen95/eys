<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\Drivers\IloPasswordDriver;
use App\Services\Rotation\Drivers\RedfishPasswordDriver;
use App\Services\Rotation\Drivers\RibclPasswordDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * iLO nesil algılama.
 *
 * Redfish iLO 3'te yok, iLO 4'te ancak firmware 2.30+ ile var. Bu sürücü
 * olmadan her iLO kaydında "bu hangi nesil" sorusunun elle
 * yanıtlanması gerekirdi ve yanlış yanıt ancak ilk döndürme denemesinde
 * ortaya çıkardı.
 */
class IloAutoDetectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Bmc,
            'host' => '198.51.100.30',
            'port' => 443,
            'url' => null,
            'username' => 'Administrator',
            'password' => 'eski-parola',
            ...$ustune,
        ]);
    }

    #[Test]
    public function bmc_platformunun_varsayilani_otomatik_surucudur(): void
    {
        $this->assertSame(IloPasswordDriver::KEY, OperatingSystem::Bmc->rotationDriver());

        $this->assertInstanceOf(
            IloPasswordDriver::class,
            app(DriverRegistry::class)->for($this->kayit()),
        );
    }

    #[Test]
    public function redfish_varsa_redfish_yolu_secilir(): void
    {
        Http::fake([
            '*/redfish/v1/' => Http::response(['@odata.id' => '/redfish/v1/', 'Name' => 'HPE RESTful Root'], 200),
            '*' => Http::response(['Members' => []], 200),
        ]);

        $this->assertSame(
            RedfishPasswordDriver::KEY,
            app(IloPasswordDriver::class)->protocolFor($this->kayit()),
        );
    }

    #[Test]
    public function redfish_yoksa_ribcl_yoluna_dusulur(): void
    {
        /* iLO 3: /redfish/v1/ diye bir uç yok. */
        Http::fake(['*/redfish/v1/' => Http::response('Not Found', 404)]);

        $this->assertSame(
            RibclPasswordDriver::KEY,
            app(IloPasswordDriver::class)->protocolFor($this->kayit()),
        );
    }

    #[Test]
    public function yoklama_parola_gondermez(): void
    {
        Http::fake(['*' => Http::response(['Name' => 'root'], 200)]);

        app(IloPasswordDriver::class)->protocolFor($this->kayit());

        /*
         * Redfish servis kökü herkese açıktır; kimlik yalnızca alt
         * kaynaklarda gerekir. Yoklamada parola göndermek, henüz hangi
         * protokolü konuştuğunu bilmediğimiz bir makineye kimlik yollamak
         * olurdu.
         */
        Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization'));
    }

    #[Test]
    public function yoklama_bir_kez_yapilir_sonuc_onbelleklenir(): void
    {
        Http::fake(['*' => Http::response(['Name' => 'root'], 200)]);

        $credential = $this->kayit();
        $driver = app(IloPasswordDriver::class);

        $driver->protocolFor($credential);
        $driver->protocolFor($credential);
        $driver->protocolFor($credential);

        Http::assertSentCount(1);
    }

    #[Test]
    public function ulasilamayan_makinede_ribcl_varsayilir(): void
    {
        Http::fake(fn () => throw new ConnectionException('baglanti yok'));

        /*
         * Yanlış tahmin güvenli: RIBCL iLO 5/6'da da çalışır, yalnızca eski
         * yoldur. Ters yönde bir varsayım iLO 3'te hiç çalışmazdı.
         */
        $this->assertSame(
            RibclPasswordDriver::KEY,
            app(IloPasswordDriver::class)->protocolFor($this->kayit()),
        );
    }

    #[Test]
    public function elle_secim_otomatigi_ezer(): void
    {
        $registry = app(DriverRegistry::class);

        $this->assertInstanceOf(
            RibclPasswordDriver::class,
            $registry->for($this->kayit(['rotation_driver' => RibclPasswordDriver::KEY])),
        );

        $this->assertInstanceOf(
            RedfishPasswordDriver::class,
            $registry->for($this->kayit(['name' => 'ikinci', 'rotation_driver' => RedfishPasswordDriver::KEY])),
        );
    }
}
