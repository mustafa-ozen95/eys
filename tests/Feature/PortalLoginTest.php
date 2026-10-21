<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Portal\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Portal oturumunun gövdesi.
 *
 * Her panel alan=değer kabul etmiyor: iLO 4 JSON bekliyor ve form gönderimini
 * 400 ile reddediyor (ölçüldü: fw 2.78/2.81). Tarayıcının `text/plain` kipi
 * gövdeye tam olarak "ad=değer" yazdığı için, adın sonunu ve değerin başını
 * doğru seçince ortaya geçerli bir JSON çıkıyor. Bu testler o gövdenin
 * gerçekten geçerli JSON olduğunu ve parolayı BOZMADAN taşıdığını kilitler.
 */
class PortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private function ilo(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'access_type' => AccessType::Ilo,
            'operating_system' => OperatingSystem::Bmc,
            'host' => '10.0.0.9',
            'port' => null,
            'url' => null,
            'username' => 'Administrator',
            'password' => 'gizli-parola',
            'login_url' => null,
            ...$ustune,
        ]);
    }

    /** Formun üreteceği gövdeyi birebir kurar: "ad=değer". */
    private function govde(PortalLogin $login): string
    {
        $ad = array_key_first($login->fields);

        return $ad.'='.$login->fields[$ad];
    }

    #[Test]
    public function ilo_govdesi_gecerli_json_uretir(): void
    {
        $login = PortalLogin::for($this->ilo());

        $this->assertNotNull($login);
        $this->assertSame('text/plain', $login->enctype);
        $this->assertSame('https://10.0.0.9/json/login_session', $login->action);

        $json = json_decode($this->govde($login), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('login', $json['method']);
        $this->assertSame('Administrator', $json['user_login']);
        $this->assertSame('gizli-parola', $json['password']);
    }

    /**
     * PORT kaydın kendisinden gelir.
     *
     * iLO 443'te olmak zorunda değil; yönetim arayüzü başka bir portta da
     * durabilir.
     */
    #[Test]
    public function ilo_adresi_kaydin_portunu_kullanir(): void
    {
        $login = PortalLogin::for($this->ilo(['port' => 4443]));

        $this->assertSame('https://10.0.0.9:4443/json/login_session', $login?->action);
    }

    /**
     * Tırnak taşıyan parola gövdeyi BOZMAMALI.
     *
     * Kaçış yapılmasaydı JSON kırılır, giriş sessizce başarısız olurdu — ve
     * bunu ancak kullanıcı fark ederdi.
     */
    #[Test]
    public function tirnakli_parola_govdeyi_bozmaz(): void
    {
        $login = PortalLogin::for($this->ilo(['password' => 'ab"cd\\ef']));

        $json = json_decode($this->govde($login), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ab"cd\\ef', $json['password']);
    }

    /**
     * Girişten sonra ARAYÜZE gidilir.
     *
     * iLO giriş isteğine JSON döndürüyor; adres taşınmasaydı kullanıcı ham
     * verinin üstünde kalır, arayüze elle gitmek zorunda olurdu.
     */
    #[Test]
    public function ilo_girisinden_sonra_arayuz_adresi_tasinir(): void
    {
        $this->assertSame('https://10.0.0.9/', PortalLogin::for($this->ilo())?->landing);
        $this->assertSame('https://10.0.0.9:4443/', PortalLogin::for($this->ilo(['port' => 4443]))?->landing);
    }

    #[Test]
    public function parolasiz_kayitta_portal_kurulmaz(): void
    {
        $this->assertNull(PortalLogin::for($this->ilo(['password' => null])));
        $this->assertNull(PortalLogin::for($this->ilo(['host' => null])));
    }

    /** Klasik panellerde davranış değişmedi: alan adları kayıttan gelir. */
    #[Test]
    public function klasik_panel_alan_deger_gonderir(): void
    {
        $credential = Credential::factory()->create([
            'access_type' => AccessType::Web,
            'operating_system' => OperatingSystem::Application,
            'host' => '10.0.0.9',
            'username' => 'admin',
            'password' => 'gizli',
            'login_url' => 'https://10.0.0.9:8443/login_up.php',
            'login_username_field' => 'login_name',
            'login_password_field' => 'passwd',
        ]);

        $login = PortalLogin::for($credential);

        $this->assertNull($login?->enctype, 'Klasik formda kip verilmez.');
        $this->assertNull($login?->landing, 'Klasik panel girişten sonra kendi ekranına yönlendirir.');
        $this->assertSame('https://10.0.0.9:8443/login_up.php', $login?->action);
        $this->assertSame(['login_name' => 'admin', 'passwd' => 'gizli'], $login?->fields);
    }

    #[Test]
    public function ilo_kaydi_ek_alan_istemeden_hazir_olur(): void
    {
        $credential = $this->ilo();

        $this->assertTrue(
            $credential->can_open_portal_session,
            'iLO kaydı giriş adresi girilmeden de portal oturumu açabilmeli.',
        );
    }
}
