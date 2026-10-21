<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\WindowsPasswordDriver;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Windows sürücüsünün sınanabilen kısmı.
 *
 * Canlı bir makineye BAĞLANILMAZ (bkz. .ai/rules/work.md). Buradaki soru
 * "parola değişiyor mu" değil — o ancak gerçek bir makinede ölçülür. Buradaki
 * soru: sürücü eksik veriyi doğru bildiriyor mu, ve makineye gönderdiği metin
 * güvenli mi.
 */
class WindowsDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Windows,
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => 'Administrator',
            'password' => 'parola',
            ...$ustune,
        ]);
    }

    private function oturum(): RotationSession
    {
        return new RotationSession(null, 'Administrator', 'parola', static fn () => null);
    }

    #[Test]
    public function eksik_alanlari_adiyla_bildirir(): void
    {
        $driver = new WindowsPasswordDriver(timeout: 1);

        $this->assertSame('Adres tanımlı değil.', $driver->unsupportedReason($this->kayit(['host' => null])));
        $this->assertSame('Kullanıcı adı tanımlı değil.', $driver->unsupportedReason($this->kayit(['username' => null])));
        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function windows_icin_gecersiz_hesap_adlari_reddedilir(): void
    {
        $driver = new WindowsPasswordDriver(timeout: 1);

        /*
         * Ad tek tırnak içinde PowerShell'e giriyor ve YALNIZCA Windows'un
         * kendi yasakladıkları reddedilir; güvenliği veren şey kaçış
         * (bkz. ManagesWindowsAccounts::escapeName).
         *
         * BOŞLUK VE TÜRKÇE HARF GEÇER. Dar bir süzgeç (`[A-Za-z0-9._-]`)
         * var olan hesapları da reddederdi: `GÖREVLİ` gibi Türkçe harfli bir
         * hesapta tur, makineye HİÇ BAĞLANMADAN "hesap adı geçersiz" diyerek
         * düşerdi. Adı biz seçmiyoruz; kasadaki
         * hesap neyse odur.
         */
        foreach (["kotu'; Remove-Item C:\\ -Recurse", 'cok-uzun-bir-hesap-adi-yirmi-karakteri-gecer', '', "ad\nsatir"] as $ad) {
            try {
                $driver->deleteRecovery($this->oturum(), $ad);
                $this->fail("Reddedilmeliydi: {$ad}");
            } catch (RotationException) {
                $this->assertTrue(true);
            }
        }
    }

    /**
     * Var olan hesap adları GEÇER ve tek tırnak kaçırılır.
     *
     * Windows kullanıcı adları Unicode'dur ve boşluk taşıyabilir; bunları
     * reddetmek, panelin hiç deneyemeyeceği hesaplar üretir.
     */
    #[Test]
    public function var_olan_hesap_adlari_kacirilarak_gecer(): void
    {
        $sinif = new \ReflectionClass(WindowsPasswordDriver::class);
        $yontem = $sinif->getMethod('escapeName');
        $yontem->setAccessible(true);

        $surucu = $sinif->newInstanceWithoutConstructor();

        $this->assertSame('GÖREVLİ', $yontem->invoke($surucu, 'GÖREVLİ'));
        $this->assertSame('ad soyad', $yontem->invoke($surucu, 'ad soyad'));

        /* Tırnak İKİLENİR: dizge erken kapanmasın. */
        $this->assertSame("o''neill", $yontem->invoke($surucu, "o'neill"));
    }

    #[Test]
    public function kurtarma_hesabi_adi_windows_sinirlarina_uyar(): void
    {
        /*
         * Rotator'ın ürettiği ad Windows'un 20 karakter sınırına sığmalı;
         * sığmazsa tur ilk adımda düşer ve bunu ancak sahada görürdük.
         */
        $method = new \ReflectionMethod(PasswordRotator::class, 'recoveryName');
        $ad = $method->invoke(app(PasswordRotator::class));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,20}$/', $ad);
    }

    #[Test]
    public function ulasilamayan_adreste_dogrulama_false_doner(): void
    {
        $driver = new WindowsPasswordDriver(timeout: 1);

        $this->assertFalse($driver->verify($this->kayit(), 'Administrator', 'herhangi'));
    }

    #[Test]
    public function ulasilamayan_adreste_baglanti_istisna_atar(): void
    {
        $driver = new WindowsPasswordDriver(timeout: 1);

        $this->expectException(RotationException::class);

        /* 127.0.0.1:1 — dinleyen bir servis yok. */
        $driver->open($this->kayit(), 'Administrator', 'parola');
    }
}
