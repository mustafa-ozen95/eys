<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Services\Rotation\PasswordRotator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePasswordDriver;
use Tests\TestCase;

/**
 * Turun HANGİ kimlikle bağlandığı ve kimliğin kendisi döndürülünce ne olduğu.
 *
 * Bu üç soru ayrı ayrı bozulabilir (örnek: ATEN CN8000A ve Lantronix Spider
 * KVM'leri):
 *
 *  1. Kurtarma hesaplı sıra, yönetim oturumunu DÖNDÜRÜLEN hesabın parolasıyla
 *     açıyordu. Bir KVM kullanıcısının kullanıcı yönetme yetkisi yok: tur
 *     ilk adımda düşerdi.
 *  2. Yöneticinin KENDİ parolası döndürülünce kaydın `rotation_password`
 *     alanı eski kalıyordu; bir sonraki tur kendi kimliğiyle bağlanamazdı.
 *  3. Aynı cihazdaki kullanıcı kayıtları yöneticinin parolasını bir KOPYA
 *     olarak taşıyor. Yönetici döndürülünce o kopyalar bayatlıyordu ve hata
 *     ancak o kayıt döndürülmeye çalışılınca görünüyordu.
 */
class RotationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private FakePasswordDriver $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = new FakePasswordDriver([
            'yonetici' => 'yonetici-parolasi',
            'kullanici' => 'kullanici-parolasi',
        ]);

        config(['rotation.drivers' => ['ssh' => FakePasswordDriver::class]]);
        $this->app->instance(FakePasswordDriver::class, $this->device);
    }

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => 'kvm-1.ornek.test',
            'username' => 'kullanici',
            'password' => 'kullanici-parolasi',
            'rotation_username' => 'yonetici',
            'rotation_password' => 'yonetici-parolasi',
            'rotation_enabled' => true,
            ...$ustune,
        ]);
    }

    #[Test]
    public function yonetim_oturumu_dondurme_kimligiyle_acilir(): void
    {
        $rotation = app(PasswordRotator::class)->rotate($this->kayit());

        $this->assertSame(RotationStatus::Succeeded, $rotation->status, $rotation->message ?? '');
        $this->assertSame(
            'yonetici',
            $this->device->lastIdentity,
            'Tur, döndürülen hesabın kendi parolasıyla bağlanmamalı: o hesabın yetkisi olmayabilir.',
        );
    }

    #[Test]
    public function kimlik_kendisi_dondurulunce_dondurme_parolasi_da_guncellenir(): void
    {
        $credential = $this->kayit([
            'username' => 'yonetici',
            'password' => 'yonetici-parolasi',
        ]);

        $rotation = app(PasswordRotator::class)->rotate($credential);
        $credential->refresh();

        $this->assertSame(RotationStatus::Succeeded, $rotation->status, $rotation->message ?? '');
        $this->assertSame(
            $credential->password,
            $credential->rotation_password,
            'Kimlik kendisi döndürüldü; kaydın döndürme parolası eski kalırsa sonraki tur bağlanamaz.',
        );
    }

    #[Test]
    public function ayni_cihazdaki_kayitlarin_dondurme_kimligi_de_guncellenir(): void
    {
        $yonetici = $this->kayit(['username' => 'yonetici', 'password' => 'yonetici-parolasi']);
        $kullanici = $this->kayit();

        app(PasswordRotator::class)->rotate($yonetici);

        $this->assertSame(
            $yonetici->fresh()->password,
            $kullanici->fresh()->rotation_password,
            'Yöneticinin yeni parolası, onu kimlik olarak kullanan kayda da yazılmalı.',
        );
    }

    #[Test]
    public function baska_cihazdaki_ayni_adli_kimlik_guncellenmez(): void
    {
        $yonetici = $this->kayit(['username' => 'yonetici', 'password' => 'yonetici-parolasi']);

        /* Aynı kullanıcı adı onlarca cihazda bulunur; adres ayırt edicidir. */
        $baskaCihaz = $this->kayit(['host' => 'kvm-2.ornek.test']);

        app(PasswordRotator::class)->rotate($yonetici);

        $this->assertSame(
            'yonetici-parolasi',
            $baskaCihaz->fresh()->rotation_password,
            'Bir cihazın parolası başka cihazın kaydına yazılmamalı.',
        );
    }

    /**
     * Cihaza ULAŞILAMAZSA kasa güncellenmez ve kapı açık bırakılır.
     *
     * Doğrulama isteği ağ hatası aldığında sürücüler `false` döndürüyordu;
     * tur bunu "yeni parola çalışmıyor" diye okuyordu. Oysa parola cihazda
     * DEĞİŞMİŞ olabilir: kasada eski, cihazda yeni kalırdı. Örnek: Lantronix
     * Spider art arda bağlantıları kısa süre reddeder; aynı parola biraz sonra
     * sorunsuz doğrulanır.
     */
    #[Test]
    public function cihaza_ulasilamazsa_kasa_bozulmaz_ve_kurtarma_hesabi_kalir(): void
    {
        $credential = $this->kayit();
        $eski = $credential->password;

        $this->device->unreachableOnVerify = true;

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertNotSame(RotationStatus::Succeeded, $rotation->status);
        $this->assertStringContainsString('ulaşılamadı', (string) $rotation->message);

        $this->assertSame($eski, $credential->fresh()->password, 'Doğrulanamayan parola kasaya yazılmamalı.');
        $this->assertTrue($rotation->recovery_active, 'Makineye dönecek kapı açık bırakılmalı.');
        $this->assertNotNull($rotation->recovery_password, 'Kurtarma parolası kayıtta durmalı.');
    }

    /**
     * CİHAZA HİÇ BAĞLANILAMADIYSA "kurtarma hesabı duruyor" UYARISI VERİLMEZ.
     *
     * Kimliği kaydetmek ile "cihazda bir hesap var" demek ayrı şeylerdi ama
     * ikisi birlikte yapılıyordu: cihaz ağdan kopuksa tur ilk adımda düşer ve
     * panelde cihazda OLMAYAN bir hesap için kırmızı uyarı asılı kalırdı.
     * Üstelik uyarıyı kapatmak cihaza bağlanmayı gerektirdiği için
     * kapatılamazdı.
     */
    #[Test]
    public function baglanilamayan_cihaz_icin_kurtarma_uyarisi_birakilmaz(): void
    {
        $credential = $this->kayit();

        /* Cihaz kapalı: ilk oturum hiç açılmıyor. */
        $this->device->accounts = [];

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertNotSame(RotationStatus::Succeeded, $rotation->status);
        $this->assertFalse(
            $rotation->recovery_active,
            'Oturum hiç açılmadıysa cihazda hesap yoktur; uyarı yanlış alarmdır.',
        );

        /* Kimlik yine de kayıtta durur: açılış yarıda kesilseydi gerekecekti. */
        $this->assertNotNull($rotation->recovery_username);
    }

    #[Test]
    public function kimlik_verilmemisse_kaydin_kendi_hesabiyla_baglanilir(): void
    {
        $credential = $this->kayit([
            'rotation_username' => null,
            'rotation_password' => null,
        ]);

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Succeeded, $rotation->status, $rotation->message ?? '');
        $this->assertSame('kullanici', $this->device->lastIdentity);
    }
}
