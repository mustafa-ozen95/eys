<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\PleskPasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSshHandle;
use Tests\TestCase;

/**
 * Plesk yönetici parolasının döndürülmesi.
 *
 * Bu sürücüde İKİ AYRI KİMLİK var: değişen (panelin admin hesabı) ve
 * değiştiren (makinenin SSH kimliği). Testlerin kilitlediği şey bu ayrım,
 * parolanın komut satırına düşmemesi ve doğrulamanın SSH ile değil PANELE
 * GİREREK yapılması.
 */
class PleskDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'access_type' => AccessType::Web,
            'operating_system' => OperatingSystem::Application,
            'host' => '10.0.0.9',
            'port' => 8443,
            'url' => 'https://10.0.0.9:8443',
            'username' => 'admin',
            'password' => 'eski-panel-parolasi',
            'rotation_enabled' => true,
            'rotation_username' => 'root',
            'rotation_password' => 'makine-parolasi',
            ...$ustune,
        ]);
    }

    #[Test]
    public function eksik_alanlar_adiyla_bildirilir(): void
    {
        $driver = new PleskPasswordDriver(timeout: 1);

        $this->assertNull($driver->unsupportedReason($this->kayit()));

        /*
         * Kimlik SEÇİLİR: aynı adresli SSH kaydı kendiliğinden kullanılmıyor,
         * yalnızca öneriliyor (bkz. MachineAccount).
         */
        $this->assertStringContainsString(
            'Güncelleme hesabı seçilmemiş',
            (string) $driver->unsupportedReason($this->kayit(['rotation_username' => null])),
        );

        $this->assertSame(
            'Bu sürücü yalnızca Plesk yönetici hesabını (admin) günceller.',
            $driver->unsupportedReason($this->kayit(['username' => 'baskasi'])),
        );
    }

    /**
     * Parola ARGÜMANDA GEÇMEZ.
     *
     * Plesk'in `--set-password` seçeneği parolayı komut satırına koyuyor ve
     * hedef makinenin süreç listesine düşürüyor — paylaşımlı bir barındırma
     * sunucusunda bu, oradaki herkese göstermek demektir. Bu yüzden parolayı
     * ortam değişkeninden okuyan araç kullanılır, değişken de standart
     * girdiden doldurulur.
     */
    #[Test]
    public function parola_ortam_degiskeninden_gecer_komut_satirina_yazilmaz(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'root', 'makine-parolasi', fn () => null);

        (new PleskPasswordDriver(timeout: 1))->changePassword($session, 'admin', 'YENI-PANEL-42');

        $this->assertStringContainsString('read -r PSA_PASSWORD', $handle->command);
        $this->assertStringContainsString('ch_admin_passwd', $handle->command);
        $this->assertStringNotContainsString('--set-password', $handle->command);
        $this->assertStringNotContainsString('YENI-PANEL-42', $handle->command);

        /* Parola yalnızca girdiden gider. */
        $this->assertSame("YENI-PANEL-42\n", $handle->writes[0]);
    }

    #[Test]
    public function admin_disinda_bir_hesap_degistirilmez(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'root', 'makine-parolasi', fn () => null);

        $this->expectException(RotationException::class);

        (new PleskPasswordDriver(timeout: 1))->changePassword($session, 'baskasi', 'YENI-PANEL-42');
    }

    /**
     * Doğrulama PANELE GİRER.
     *
     * Makineye SSH ile bağlanabilmek, panel parolasının çalıştığını
     * kanıtlamaz — değişen şey panelin parolasıdır.
     *
     * ÖLÇÜLDÜ (Plesk Obsidian 18.0.80.6): doğru parola 303, yanlış parola
     * 200 döndürüyor.
     */
    #[Test]
    public function dogrulama_panele_girerek_yapilir(): void
    {
        Http::fake(['*/login_up.php' => Http::response('', 303)]);

        $this->assertTrue((new PleskPasswordDriver(timeout: 1))->verify($this->kayit(), 'admin', 'yeni'));

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'https://10.0.0.9:8443/login_up.php')
            && $r['login_name'] === 'admin'
            && $r['passwd'] === 'yeni');
    }

    #[Test]
    public function yanlis_parolada_dogrulama_false_doner(): void
    {
        /* Plesk yanlış parolada yönlendirmez, giriş sayfasını yeniden basar. */
        Http::fake(['*/login_up.php' => Http::response('<html>giriş</html>', 200)]);

        $this->assertFalse((new PleskPasswordDriver(timeout: 1))->verify($this->kayit(), 'admin', 'yanlis'));
    }

    #[Test]
    public function panele_ulasilamazsa_dogrulama_false_doner(): void
    {
        Http::fake(fn () => throw new \RuntimeException('baglanti yok'));

        $this->assertFalse((new PleskPasswordDriver(timeout: 1))->verify($this->kayit(), 'admin', 'yeni'));
    }

    /**
     * Kurtarma hesabı açılmaz.
     *
     * Değiştiren kimlik (SSH) ile değişen parola (panel) ayrı; kapı zaten
     * açık kalıyor.
     */
    #[Test]
    public function kurtarma_hesabi_acilmaz(): void
    {
        $driver = new PleskPasswordDriver(timeout: 1);

        $this->assertFalse($driver->supportsRecovery());
        $this->assertTrue($driver->canVerify());
    }
}
