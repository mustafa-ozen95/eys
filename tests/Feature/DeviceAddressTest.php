<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Services\DeviceAddress;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kaydın web adresi.
 *
 * Bu hesap sekiz ayrı dosyada kopyalanmıştı; port ve şema kuralı yerden yere
 * değişiyordu. Tek kural burada kilitlenir.
 */
class DeviceAddressTest extends TestCase
{
    private function kayit(array $alanlar): Credential
    {
        return (new Credential)->forceFill($alanlar);
    }

    /**
     * PORT adrese yazılır.
     *
     * Bir iLO standart dışı bir portta (ör. 4443) durabilir; portu düşüren bir
     * adres yanlış kapıyı çalar ve hata "cihaz kapalı" gibi görünürdü.
     */
    #[Test]
    public function port_443_disinda_adrese_yazilir(): void
    {
        $this->assertSame('https://192.0.2.10:4443', DeviceAddress::for($this->kayit([
            'host' => '192.0.2.10', 'port' => 4443, 'url' => null,
        ])));

        $this->assertSame('https://192.0.2.20', DeviceAddress::for($this->kayit([
            'host' => '192.0.2.20', 'port' => 443, 'url' => null,
        ])));

        $this->assertSame('https://192.0.2.20', DeviceAddress::for($this->kayit([
            'host' => '192.0.2.20', 'port' => null, 'url' => null,
        ])));
    }

    /** Portu yazılmamış Plesk kaydı 443'e değil, panelin kendi portuna gider. */
    #[Test]
    public function port_yoksa_platformun_olagan_portu_varsayilir(): void
    {
        $this->assertSame('https://192.0.2.30:8443', DeviceAddress::for($this->kayit([
            'host' => '192.0.2.30', 'port' => null, 'url' => null,
        ]), assumedPort: 8443));
    }

    /**
     * Sürücü BAŞKA KAPIDAN konuşabilir.
     *
     * vCenter yönetim arayüzü 5480'de ama REST API 443'te; kaydın portunu
     * "düzeltmek" kullanıcının bağlantısını kırardı.
     */
    #[Test]
    public function surucu_kaydin_portunu_bozmadan_baska_porta_gidebilir(): void
    {
        $kayit = $this->kayit(['host' => '192.0.2.40', 'port' => 5480, 'url' => null]);

        $this->assertSame('https://192.0.2.40:5480', DeviceAddress::for($kayit));
        $this->assertSame('https://192.0.2.40', DeviceAddress::port($kayit, 443));
        $this->assertSame('https://192.0.2.40:22', DeviceAddress::port($kayit, 22));
    }

    /**
     * ŞEMA TAHMİN EDİLMEZ: kullanıcı http yazdıysa http'ye gidilir.
     *
     * İki şema aynı kapı değil: bir cihaz düz http'yi https'e yönlendirir,
     * bir başkası http'ye hiç yanıt vermez.
     */
    #[Test]
    public function kayitta_yazan_sema_korunur(): void
    {
        $this->assertSame('http://192.0.2.50:8080', DeviceAddress::for($this->kayit([
            'host' => '192.0.2.50', 'port' => 443, 'url' => 'http://192.0.2.50:8080/',
        ])));

        $this->assertSame('https://panel.ornek.test', DeviceAddress::for($this->kayit([
            'host' => '192.0.2.50', 'port' => 4443, 'url' => 'https://panel.ornek.test/',
        ])));
    }

    /**
     * Şemasız yazılmış adres https sayılır.
     *
     * Kimlik taşıyan bir isteği kendiliğimizden düz http'ye düşürmek parolayı
     * ağa açıkta yazmak olurdu.
     */
    #[Test]
    public function semasiz_adres_https_sayilir(): void
    {
        $this->assertSame('https://192.0.2.50:4443', DeviceAddress::for($this->kayit([
            'host' => null, 'port' => null, 'url' => '192.0.2.50:4443',
        ])));
    }
}
