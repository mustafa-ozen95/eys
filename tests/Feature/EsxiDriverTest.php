<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\EsxiPasswordDriver;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ESXi sürücüsü.
 *
 * Canlı bir ESXi'ye BAĞLANILMAZ; SOAP yanıtları taklit edilir
 * (bkz. .ai/rules/work.md). Sınanan şey: sürücü doğru zarfı gönderiyor,
 * nesne kimliklerini SUNUCUDAN okuyor ve hata gövdesini kaçırmıyor mu.
 */
class EsxiDriverTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_CONTENT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body><RetrieveServiceContentResponse xmlns="urn:vim25"><returnval>
            <rootFolder type="Folder">ha-folder-root</rootFolder>
            <sessionManager type="SessionManager">ha-sessionmgr</sessionManager>
            <authorizationManager type="AuthorizationManager">ha-authmgr</authorizationManager>
            <accountManager type="HostLocalAccountManager">ha-localacctmgr</accountManager>
          </returnval></RetrieveServiceContentResponse></soapenv:Body>
        </soapenv:Envelope>
        XML;

    private const OK = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body><LoginResponse xmlns="urn:vim25"><returnval><key>abc</key></returnval></LoginResponse></soapenv:Body>
        </soapenv:Envelope>
        XML;

    private const FAULT = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">
          <soapenv:Body><soapenv:Fault><faultcode>ServerFaultCode</faultcode>
          <faultstring>Cannot complete login due to an incorrect user name or password.</faultstring>
          </soapenv:Fault></soapenv:Body>
        </soapenv:Envelope>
        XML;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Esxi,
            'host' => '198.51.100.10',
            'port' => 443,
            'url' => null,
            'username' => 'root',
            'password' => 'eski-parola',
            ...$ustune,
        ]);
    }

    /** Sırayla verilen gövdeleri döndüren sahte sunucu. */
    private function sunucu(array $bodies): void
    {
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push($body, 200, ['Set-Cookie' => 'vmware_soap_session="x"; Path=/sdk']);
        }

        Http::fake(['*' => $sequence]);
    }

    #[Test]
    public function eksik_alanlari_adiyla_bildirir(): void
    {
        $driver = new EsxiPasswordDriver;

        $this->assertSame('Adres tanımlı değil.', $driver->unsupportedReason($this->kayit(['host' => null, 'url' => null])));
        $this->assertSame('Kullanıcı adı tanımlı değil.', $driver->unsupportedReason($this->kayit(['username' => null])));
        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function nesne_kimlikleri_sunucudan_okunur_sabit_yazilmaz(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::OK]);

        (new EsxiPasswordDriver)->open($this->kayit(), 'root', 'eski-parola');

        /*
         * İlk istek servis bilgisini sorar, ikincisi girişte O bilgiden gelen
         * oturum yöneticisini kullanır. Kimlik sabit yazılsaydı bu test
         * sunucunun ne döndürdüğünden bağımsız geçerdi.
         */
        Http::assertSent(fn ($r) => str_contains($r->body(), 'RetrieveServiceContent'));
        Http::assertSent(fn ($r) => str_contains($r->body(), '<Login')
            && str_contains($r->body(), 'ha-sessionmgr'));
    }

    #[Test]
    public function soap_hatasi_yakalanir_ve_nedeni_tasinir(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::FAULT]);

        $driver = new EsxiPasswordDriver;

        try {
            $driver->open($this->kayit(), 'root', 'yanlis');
            $this->fail('İstisna atmalıydı.');
        } catch (RotationException $e) {
            /* Sunucunun anlattığı neden kaybedilmemeli. */
            $this->assertStringContainsString('incorrect user name or password', $e->getMessage());
        }
    }

    #[Test]
    public function yanlis_parolada_dogrulama_false_doner(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::FAULT]);

        /* Doğrulama istisna atmaz; rotator bu yanıta bakıp karar veriyor. */
        $this->assertFalse((new EsxiPasswordDriver)->verify($this->kayit(), 'root', 'yanlis'));
    }

    #[Test]
    public function kurtarma_hesabi_acilinca_yonetici_yetkisi_de_verilir(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::OK, self::OK, self::OK]);

        $driver = new EsxiPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'yeni-gecici');

        /*
         * Eleman adı 'user' — 'account' değil. Resmi WSDL'den üretilen SDK
         * (govmomi vim25/types) CreateUserRequestType.User alanını
         * `xml:"user,typeattr"` olarak tanımlıyor. Bu test o adı kilitliyor;
         * ilk yazımda 'account' yazılmıştı ve ESXi isteği reddederdi.
         */
        Http::assertSent(fn ($r) => str_contains($r->body(), '<CreateUser')
            && str_contains($r->body(), '<user xsi:type="HostAccountSpec">')
            && str_contains($r->body(), 'ha-localacctmgr'));

        /*
         * Yetkisiz bir ESXi hesabı oturum bile açamaz; yetki verilmezse
         * kurtarma hesabı kanıt aşamasında düşerdi.
         */
        Http::assertSent(function ($r) {
            if (! str_contains($r->body(), 'SetEntityPermissions')) {
                return false;
            }

            /*
             * Permission.entity boş bırakılmalı ("Left unset when calling
             * setPermissions"); hedef dıştaki <entity> ile veriliyor. Bu
             * yüzden gövdede tam olarak BİR tane <entity> olmalı.
             */
            return str_contains($r->body(), '<roleId>-1</roleId>')
                && substr_count($r->body(), '<entity') === 1;
        });
    }

    #[Test]
    public function parolada_xml_karakterleri_kacisla_gonderilir(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::OK, self::OK]);

        $driver = new EsxiPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        $driver->changePassword($session, 'root', 'a<b&c>d');

        /* Kaçış yapılmasaydı zarf bozulur, sunucu anlamsız bir hata dönerdi. */
        Http::assertSent(fn ($r) => str_contains($r->body(), 'a&lt;b&amp;c&gt;d'));
    }

    /**
     * RemoveUser'ın biçimi.
     *
     * Diğer iki hesap çağrısı HostAccountSpec alırken bu düz `<userName>`
     * alıyor (ESXi 8.0.3'te doğrulandı); spec
     * gönderilse hesap silinemez ve tur, makinede açık bir yönetici hesabı
     * bırakarak yarıda kalırdı. Bu test o biçimi kilitler.
     */
    #[Test]
    public function kurtarma_hesabi_user_name_ile_silinir(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::OK, self::OK]);

        $driver = new EsxiPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        $driver->deleteRecovery($session, 'eys-kurtarma-abc123');

        Http::assertSent(fn ($r) => str_contains($r->body(), '<RemoveUser')
            && str_contains($r->body(), '<userName>eys-kurtarma-abc123</userName>')
            && ! str_contains($r->body(), 'HostAccountSpec')
            && str_contains($r->body(), 'ha-localacctmgr'));
    }

    #[Test]
    public function gecersiz_hesap_adi_reddedilir(): void
    {
        $this->sunucu([self::SERVICE_CONTENT, self::OK]);

        $driver = new EsxiPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        $this->expectException(RotationException::class);

        $driver->deleteRecovery($session, 'kotu ad<script>');
    }
}
