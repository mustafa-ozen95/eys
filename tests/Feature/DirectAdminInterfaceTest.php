<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\DeviceInterface\InterfaceLogin;
use App\Services\DeviceInterface\InterfaceType;
use App\Services\Portal\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * DIRECTADMIN MÜŞTERİ PORTALI — oturumu SUNUCU açar.
 *
 * Portal, giriş isteğini kendi sayfasından gelmiş saymak istiyor ve bunu
 * `Referer` başlığıyla denetliyor. Portalın davranışı:
 *
 *     Referer: https://www.directadmin.com/  → gövde "true"
 *     Referer yok ya da yabancı köken        → "Referer check has failed"
 *     ajax_login'siz düz gönderim            → giriş olmuyor
 *
 * Başlığı TARAYICI yazar; panelin "tek tıkla oturum aç" formu (Plesk'te
 * çalışan yol) bu portalda hiçbir zaman geçemez. Geriye tek yol kalıyor:
 * giriş sunucuda yapılır, oturum panelde durur, arayüz vekilden verilir —
 * böylece parola kullanıcının tarayıcısına hiç inmez: kullanıcı parolayı
 * ne görür ne kaydedebilir.
 */
final class DirectAdminInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'name' => 'Direct Admin',
            'access_type' => AccessType::License,
            'host' => null,
            'port' => null,
            'url' => 'https://www.directadmin.com/clients/index.php',
            'login_url' => null,
            'username' => '10001',
            'password' => 'LISANS-PAROLASI',
            ...$ustune,
        ]);
    }

    #[Test]
    public function kayit_adresinden_taninir(): void
    {
        config(['remote.arayuz.turler' => ['directadmin']]);

        $this->assertSame(InterfaceType::DirectAdmin, InterfaceType::icin($this->kayit()));
    }

    /** BAŞKA BİR ADRES bu arayüze girmez. */
    #[Test]
    public function baska_adres_taninmaz(): void
    {
        config(['remote.arayuz.turler' => ['directadmin']]);

        $this->assertNull(InterfaceType::icin($this->kayit(['url' => 'https://baska.ornek.test/clients/'])));
    }

    /**
     * VEKİL AÇIKKEN FORM YOLU DEVREDE OLMAZ.
     *
     * İki mekanizma aynı kaydı sahiplenseydi hangisinin çalıştığı ekrandaki
     * dalların sırasına kalırdı; üstelik form yolu bu portalda çalışmıyor.
     */
    #[Test]
    public function vekil_acikken_form_yolu_devre_disi(): void
    {
        config(['remote.arayuz.turler' => ['directadmin']]);

        $this->assertFalse(PortalLogin::hazirMi($this->kayit()));
    }

    /** Giriş SUNUCUDA yapılır: istek REFERER taşır ve iki adımdır. */
    #[Test]
    public function giris_sunucuda_referer_ile_yapilir(): void
    {
        Http::fake([
            'www.directadmin.com/clients/index.php' => Http::response('', 200, [
                'Set-Cookie' => 'PHPSESSID=abc123; path=/',
            ]),
            'www.directadmin.com/clients/login.php' => Http::response('true', 200),
        ]);

        $cerez = app(InterfaceLogin::class)->ac(
            InterfaceType::DirectAdmin,
            $this->kayit(),
            'www.directadmin.com',
        );

        $this->assertStringContainsString('PHPSESSID=abc123', $cerez);

        Http::assertSent(function ($istek): bool {
            if (! str_contains($istek->url(), 'login.php')) {
                return true;
            }

            return $istek['ajax_login'] === '1'
                && $istek['uid'] === '10001'
                && $istek['password'] === 'LISANS-PAROLASI'
                && str_starts_with((string) $istek->header('Referer')[0], 'https://www.directadmin.com/');
        });
    }

    /**
     * PORTAL HATAYI DA 200 İLE DÖNÜYOR.
     *
     * Durum koduna bakmak yanlış parolayı başarılı sayardı; gövde "true"
     * değilse oturum açılmamıştır.
     */
    #[Test]
    public function govde_true_degilse_oturum_acilmaz(): void
    {
        Http::fake([
            'www.directadmin.com/clients/index.php' => Http::response('', 200, [
                'Set-Cookie' => 'PHPSESSID=abc123; path=/',
            ]),
            'www.directadmin.com/clients/login.php' => Http::response('Referer check has failed', 200),
        ]);

        $this->expectException(RuntimeException::class);

        app(InterfaceLogin::class)->ac(InterfaceType::DirectAdmin, $this->kayit(), 'www.directadmin.com');
    }

    /** Arayüz portalın kendi dizininden verilir ve kök yolları da vekillenir. */
    #[Test]
    public function yollar_portalin_kendi_dizinidir(): void
    {
        /*
         * TABAN BİREBİR, İNİŞ AYRI. Müşteri alanı /clients/ altında ama
         * sayfanın varlıkları kökte (/css/, /js/, /vendor/); taban /clients/
         * olsaydı vekil onları /clients/vendor/... diye ister ve 404 alırdı.
         */
        $this->assertSame('/', InterfaceType::DirectAdmin->cihazYolu());
        $this->assertSame('/clients/', InterfaceType::DirectAdmin->inisYolu());
        $this->assertTrue(InterfaceType::DirectAdmin->sunucudaGiris());

        /*
         * KÖK YOLU YOK: arayüz oturum çerezli dinleyiciden açılıyor ve orada
         * kökte duruyor. Panelin ana kökenine yol açmak gereksizdi.
         */
        $this->assertSame([], InterfaceType::DirectAdmin->kokYollari());
    }

    /**
     * İSTEK CİHAZIN KÖKENİNDEN GELMİŞ GÖRÜNÜR.
     *
     * Portal yalnızca girişte değil HER istekte `Referer` denetliyor; vekilin
     * kökenini gördüğünde "Referer check has failed" diyor ve giriş çalışsa
     * bile portalda gezilemiyordu.
     *
     * Yalnızca isteyen türde açılır: çalışan bir arayüzü, ihtiyacı olmayan
     * bir düzeltmeyle bozmanın anlamı yok.
     */
    #[Test]
    public function koken_yalnizca_bu_turde_yeniden_kurulur(): void
    {
        $this->assertTrue(InterfaceType::DirectAdmin->kokenYazilirMi());

        foreach ([InterfaceType::Plesk, InterfaceType::Ilo, InterfaceType::Esxi, InterfaceType::Vami, InterfaceType::Vcenter] as $tur) {
            $this->assertFalse($tur->kokenYazilirMi(), $tur->value.' için köken yazılmamalı.');
        }
    }

    /**
     * BAĞLANTI HAVUZUNA ÖTEKİLERLE BİRLİKTE GİRER.
     *
     * Dışarıda olduğu için havuzdan çıkarılmaz. Havuzsuz hedef nginx'te
     * DEĞİŞKENDEN okunuyor, nginx
     * o adı istek anında çözmek istiyor ve çözümleyicisi, AD bit'i set eden
     * DNS yanıtlarını reddediyor — "invalid UDP DNS response ... fl:81A0",
     * istek zaman aşımına uğruyor (AD bit'ini set eden çözümleyiciler, ör.
     * Docker'ın gömülü DNS'i, bunu tetikler).
     *
     * Üstelik dış alan adı bu panelde yeni değil: Plesk kayıtları da
     * srv-a.ornek.test gibi adlarla havuza girebiliyor ve nginx onları
     * açılışta çözüyor.
     */
    #[Test]
    public function baglanti_havuzuna_girer(): void
    {
        $bu = new \ReflectionClass(InterfaceType::class);

        $this->assertFalse($bu->hasMethod('havuzaGirer'));
    }
}
