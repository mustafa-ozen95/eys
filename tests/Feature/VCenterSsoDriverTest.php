<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\Drivers\VCenterSsoPasswordDriver;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * vCenter SSO sürücüsü.
 *
 * Canlı bir vCenter'a BAĞLANILMAZ; sınanan şey sözleşme: hangi uca, hangi
 * başlıkla, hangi alan adlarıyla gidiyoruz. Üçü de vCenter 8.0.3'te
 * denenerek bulundu ve üçü de yanlış olduğunda hata yanıltıcıydı:
 *
 *  - SOAPAction yoksa sunucu "namespace desteklenmiyor" diyor,
 *  - parola alanının adı userId değil userName,
 *  - parola 20 karakteri aşarsa politika reddediyor.
 */
class VCenterSsoDriverTest extends TestCase
{
    use RefreshDatabase;

    /** Onaylama içeren, geçerli bir STS yanıtı. */
    private const STS = '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<S:Body><saml2:Assertion xmlns:saml2="urn:oasis:names:tc:SAML:2.0:assertion" ID="_abc">'
        .'<saml2:Issuer>STS</saml2:Issuer></saml2:Assertion></S:Body></S:Envelope>';

    private const OK = '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/">'
        .'<S:Body><ResponseOk xmlns="urn:sso"/></S:Body></S:Envelope>';

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '198.51.100.13',
            'port' => null,
            'url' => null,
            'username' => 'administrator@vsphere.local',
            'password' => 'eski-parola',
            ...$ustune,
        ]);
    }

    private function vcenter(): void
    {
        Http::fake([
            '*/sts/STSService/*' => Http::response(self::STS, 200),
            '*/sso-adminserver/sdk/*' => Http::response(self::OK, 200),
            '*/api/session' => Http::response('"jeton"', 201),
        ]);
    }

    #[Test]
    public function eksik_alanlar_adiyla_bildirilir(): void
    {
        $driver = new VCenterSsoPasswordDriver;

        $this->assertNull($driver->unsupportedReason($this->kayit()));

        $this->assertSame(
            'SSO kullanıcısı alan adıyla yazılmalı (ör. administrator@vsphere.local).',
            $driver->unsupportedReason($this->kayit(['username' => 'administrator'])),
        );
    }

    /**
     * SOAPAction ZORUNLU.
     *
     * Başlıksız istek "Request method namespace 'urn:sso' is not supported"
     * ile reddediliyor — hata ad alanını suçladığı için nedeni yanlış yerde
     * aranır. Bu test o başlığı kilitler.
     */
    #[Test]
    public function yonetim_cagrisi_surumlu_soapaction_tasir(): void
    {
        $this->vcenter();

        $driver = new VCenterSsoPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator@vsphere.local', 'eski-parola');

        $driver->deleteRecovery($session, 'eys-kurtarma-abc123');

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/sso-adminserver/sdk/vsphere.local')
            && $r->hasHeader('SOAPAction', 'urn:sso/version2'));
    }

    /** Alan adı kaydın kullanıcı adından türetilir; koda gömülmez. */
    #[Test]
    public function alan_adi_kullanicidan_turetilir(): void
    {
        $this->vcenter();

        $driver = new VCenterSsoPasswordDriver;
        $driver->open($this->kayit(['username' => 'yonetici@ornek.local']), 'yonetici@ornek.local', 'p');

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/sts/STSService/ornek.local'));
    }

    /**
     * Parola alanının adı `userName`.
     *
     * `userId` gönderildiğinde cihaz "Missing required parameter 'userName'"
     * diyor ve tur, parola değişmemişken "değişti" sanılabilecek bir yerde
     * duruyordu.
     */
    #[Test]
    public function parola_user_name_alaniyla_degistirilir(): void
    {
        $this->vcenter();

        $driver = new VCenterSsoPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator@vsphere.local', 'eski');

        $driver->changePassword($session, 'administrator@vsphere.local', 'YENI-PAROLA-42');

        Http::assertSent(function (Request $r): bool {
            $govde = $r->body();

            return str_contains($govde, '<ResetLocalPersonUserPassword')
                /* Alan adsız gider: SOAP tarafı alan adını ayrıca istemiyor. */
                && str_contains($govde, '<userName>administrator</userName>')
                && ! str_contains($govde, '<userId>')
                && str_contains($govde, '<newPassword>YENI-PAROLA-42</newPassword>');
        });
    }

    /** Kurtarma hesabı yetkiyi GRUPTAN alır; yetkisiz hesap giriş yapamaz. */
    #[Test]
    public function kurtarma_hesabi_yonetici_grubuna_alinir(): void
    {
        $this->vcenter();

        $driver = new VCenterSsoPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator@vsphere.local', 'eski');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'gecici');

        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<CreateLocalPersonUser'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->body(), '<AddUsersToLocalGroup')
            && str_contains($r->body(), '<groupName>Administrators</groupName>'));
    }

    /**
     * Doğrulama SOAP'a değil GİRİŞE bakar.
     *
     * SOAP'ın "tamam" demesi parolanın yazıldığını gösterir, çalıştığını
     * değil.
     */
    #[Test]
    public function dogrulama_rest_oturumu_acar(): void
    {
        $this->vcenter();

        $this->assertTrue((new VCenterSsoPasswordDriver)->verify($this->kayit(), 'eys-kurtarma-abc123', 'gecici'));

        /* Alan adı olmayan kurtarma hesabına alan eklenir, yoksa giriş reddedilir. */
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/session')
            && $r->hasHeader('Authorization',
                'Basic '.base64_encode('eys-kurtarma-abc123@vsphere.local:gecici')));
    }

    #[Test]
    public function reddedilen_kimlikte_dogrulama_false_doner(): void
    {
        Http::fake(['*/api/session' => Http::response('', 401)]);

        $this->assertFalse((new VCenterSsoPasswordDriver)->verify($this->kayit(), 'administrator@vsphere.local', 'yanlis'));
    }

    /** Onaylama gelmezse tur BAŞLAMAZ; yarım kalmış bir tur bırakılmaz. */
    #[Test]
    public function jeton_alinamazsa_oturum_acilmaz(): void
    {
        Http::fake(['*/sts/STSService/*' => Http::response('<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body/></S:Envelope>', 200)]);

        $this->expectExceptionMessage('vCenter SSO kimliği kabul edilmedi.');

        (new VCenterSsoPasswordDriver)->open($this->kayit(), 'administrator@vsphere.local', 'yanlis');
    }

    /** Cihazın hatası gövdede anlatılıyor; yalnızca duruma bakmak onu kaybederdi. */
    #[Test]
    public function cihazin_hata_metni_mesaja_tasinir(): void
    {
        Http::fake([
            '*/sts/STSService/*' => Http::response(self::STS, 200),
            '*/sso-adminserver/sdk/*' => Http::response(
                '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><S:Fault>'
                .'<faultstring>password policy is not satisfied</faultstring></S:Fault></S:Body></S:Envelope>',
                500,
            ),
        ]);

        $driver = new VCenterSsoPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator@vsphere.local', 'eski');

        try {
            $driver->createRecovery($session, 'eys-kurtarma-abc123', 'kisa');
            $this->fail('İstisna atmalıydı.');
        } catch (RotationException $e) {
            $this->assertStringContainsString('password policy', $e->getMessage());
        }
    }

    /** ÖLÇÜLDÜ: politika 8-20 karakter istiyor. */
    #[Test]
    public function parola_uzunlugu_20_ile_sinirlidir(): void
    {
        $this->assertSame(20, (new VCenterSsoPasswordDriver)->maxPasswordLength());
    }

    /**
     * Sürücü seçim listesi kaydın DEĞERİNİ kaybetmemeli.
     *
     * Listeden bir anahtar çıkarılırsa, o sürücüyü kullanan kaydı düzenleyen
     * kişi seçili değeri listede bulamaz ve farkında olmadan sıfırlar. Bu
     * yüzden liste her zaman tanımlı sürücülerin TAMAMINI içerir; okunurluk
     * etiketle sağlanır.
     */
    #[Test]
    public function surucu_listesi_tanimli_her_surucuyu_icerir(): void
    {
        $registry = app(DriverRegistry::class);

        $secenekler = $registry->options();

        foreach (array_keys($registry->drivers()) as $anahtar) {
            $this->assertArrayHasKey($anahtar, $secenekler, "Listede yok: {$anahtar}");
            $this->assertNotSame($anahtar, $secenekler[$anahtar], "Etiketsiz kalmış: {$anahtar}");
        }

        $this->assertArrayHasKey('', $secenekler, 'Varsayılan seçenek durmalı.');
    }
}
