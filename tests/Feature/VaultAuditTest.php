<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePasswordDriver;
use Tests\TestCase;

/**
 * Kasa denetimi.
 *
 * Bir kaydın "hazır" görünmesi, güncellemenin yürüyeceği anlamına gelmiyordu:
 * güncelleyen kimlik bir kopyadır ve asıl kayıt döndürüldüğünde eski kalır.
 * Komut o çürümeyi görünür kılar.
 */
class VaultAuditTest extends TestCase
{
    use RefreshDatabase;

    private FakePasswordDriver $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = new FakePasswordDriver(['root' => 'dogru-parola', 'guncelleyen' => 'dogru-kimlik']);

        config(['rotation.drivers' => ['ssh' => FakePasswordDriver::class]]);
        $this->app->instance(FakePasswordDriver::class, $this->device);
    }

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.9',
            'username' => 'root',
            'password' => 'dogru-parola',
            'rotation_enabled' => true,
            ...$ustune,
        ]);
    }

    #[Test]
    public function saglam_kayitlar_icin_basariyla_biter(): void
    {
        $this->kayit(['name' => 'Saglam kayit']);

        $this->artisan('erisim:kasa-denetle')
            ->expectsOutputToContain('GEÇERLİ')
            ->assertSuccessful();
    }

    /**
     * BAYAT güncelleyen kimlik yakalanır.
     *
     * Kayıtlı parola doğru olduğu için ekran "hazır" diyor; sorun yalnızca
     * güncellemeyi YAPACAK kimlikte. Komut ikisini ayrı sorar.
     */
    #[Test]
    public function bayat_guncelleyen_kimlik_yakalanir(): void
    {
        $this->kayit([
            'name' => 'Bayat kimlikli kayit',
            'rotation_username' => 'guncelleyen',
            'rotation_password' => 'ESKI-KIMLIK',
        ]);

        $this->artisan('erisim:kasa-denetle')
            ->expectsOutputToContain('GEÇERSİZ')
            ->assertFailed();
    }

    /** Kayıtlı parola makinede değişmişse de bildirilir. */
    #[Test]
    public function bayat_kayitli_parola_yakalanir(): void
    {
        $this->kayit(['name' => 'Bayat parolali kayit', 'password' => 'ARTIK-GECMEYEN']);

        $this->artisan('erisim:kasa-denetle')->assertFailed();
    }

    /** Komut hiçbir şeyi DEĞİŞTİRMEZ: denetim, işlem değil. */
    #[Test]
    public function denetim_makineye_dokunmaz(): void
    {
        $this->kayit();

        $oncesi = $this->device->accounts;

        $this->artisan('erisim:kasa-denetle')->assertSuccessful();

        $this->assertSame($oncesi, $this->device->accounts, 'Denetim hesapları değiştirmemeli.');
    }
}
