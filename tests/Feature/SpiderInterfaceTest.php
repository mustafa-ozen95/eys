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
 * LANTRONIX SPIDER (KVM over IP) — oturumu SUNUCU açar.
 *
 * Cihazın giriş ucu sabit ve klasik: form gönderilir, yanıtta oturum çerezi
 * gelir. Cihazın yanıtı:
 *
 *     POST /auth.asp  login=…&password=…&action_login=Login
 *       → 302
 *       → Set-Cookie: pp_session_id=<64 onaltılık>; path=/
 *       → Location: /home.asp
 *
 * Bu yüzden parola tarayıcıya hiç inmez: giriş panelde yapılır, çerez
 * panelde durur, vekil her isteğe onu ekler.
 *
 * KVM GÖRÜNTÜSÜ BU YOLDAN GELMEZ ve bu bilinçli: cihazın görüntüleyicisi
 * Java tabanlı, tarayıcılar Java applet'i yıllardır çalıştırmıyor. Vekilin
 * taşıdığı şey YÖNETİM ARAYÜZÜ — ayarlar, kullanıcılar, güç.
 */
final class SpiderInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'name' => 'Erişim (kvmyonetici)',
            'access_type' => AccessType::Panel,
            'host' => 'kvm.ornek.test',
            'port' => null,
            'url' => 'http://kvm.ornek.test',
            'login_url' => 'https://kvm.ornek.test/auth.asp',
            'username' => 'kvmyonetici',
            'password' => 'CIHAZ-PAROLASI',
            ...$ustune,
        ]);
    }

    #[Test]
    public function giris_adresinden_taninir(): void
    {
        config(['remote.interface.types' => ['spider']]);

        $this->assertSame(InterfaceType::Spider, InterfaceType::icin($this->kayit()));
    }

    /** Giriş adresi başka bir cihazınsa bu arayüze girmez. */
    #[Test]
    public function baska_giris_adresi_taninmaz(): void
    {
        config(['remote.interface.types' => ['spider']]);

        $this->assertNull(InterfaceType::icin($this->kayit(['login_url' => 'https://kvm.ornek.test/giris.php'])));
    }

    /**
     * VEKİL AÇIKKEN FORM YOLU DEVREDE OLMAZ.
     *
     * İkisi de "oturum aç" diyor ama biri parolayı tarayıcıya veriyor, öteki
     * vermiyor; iki mekanizma aynı kaydı sahiplenseydi hangisinin çalıştığı
     * ekrandaki dalların sırasına kalırdı.
     */
    #[Test]
    public function vekil_acikken_form_yolu_devre_disi(): void
    {
        config(['remote.interface.types' => ['spider']]);

        $this->assertFalse(PortalLogin::hazirMi($this->kayit([
            'login_username_field' => 'login',
            'login_password_field' => 'password',
        ])));
    }

    /** Giriş SUNUCUDA yapılır ve cihazın oturum çerezi panelde kalır. */
    #[Test]
    public function oturum_cerezi_sunucuda_alinir(): void
    {
        Http::fake([
            'kvm.ornek.test/auth.asp' => Http::response('', 302, [
                'Set-Cookie' => 'pp_session_id=TEST-OTURUM-0001; path=/',
                'Location' => '/home.asp',
            ]),
        ]);

        $cerez = app(InterfaceLogin::class)->ac(InterfaceType::Spider, $this->kayit(), 'kvm.ornek.test');

        $this->assertSame('pp_session_id=TEST-OTURUM-0001', $cerez);

        Http::assertSent(fn ($istek): bool => $istek['login'] === 'kvmyonetici'
            && $istek['password'] === 'CIHAZ-PAROLASI'
            && $istek['action_login'] === 'Login');
    }

    /**
     * ÇEREZ YOKSA OTURUM YOK.
     *
     * Durum koduna bakmak yanlış parolayı başarılı sayardı: cihaz yanlış
     * kimlikte de 200/302 dönebiliyor. Oturumun açıldığının tek kanıtı
     * çerezin kendisi.
     */
    #[Test]
    public function cerez_gelmezse_oturum_acilmaz(): void
    {
        Http::fake(['kvm.ornek.test/auth.asp' => Http::response('', 302, ['Location' => '/auth.asp'])]);

        $this->expectException(RuntimeException::class);

        app(InterfaceLogin::class)->ac(InterfaceType::Spider, $this->kayit(), 'kvm.ornek.test');
    }

    /**
     * İNİŞ CİHAZIN KENDİ ANA SAYFASIDIR.
     *
     * Cihazın 302'si birebir buraya işaret ediyor; kökte giriş formu var ve
     * oturum açıkken orada beklemenin anlamı yok.
     */
    #[Test]
    public function yollar_cihazin_kendi_sayfalari(): void
    {
        $this->assertSame('/', InterfaceType::Spider->cihazYolu());
        $this->assertSame('/home.asp', InterfaceType::Spider->inisYolu());
        $this->assertTrue(InterfaceType::Spider->sunucudaGiris());
        $this->assertSame(443, InterfaceType::Spider->varsayilanPort());
        $this->assertSame([], InterfaceType::Spider->kokYollari());
    }

    /**
     * KÖKEN YENİDEN KURULMAZ.
     *
     * Bunu yalnızca DirectAdmin istiyor (her istekte Referer denetliyor);
     * ihtiyacı olmayan bir arayüzde aynı düzeltmeyi açmak, çalışan bir şeyi
     * bozma riskinden başka bir şey getirmez.
     */
    #[Test]
    public function koken_yeniden_kurulmaz(): void
    {
        $this->assertFalse(InterfaceType::Spider->kokenYazilirMi());
        $this->assertSame([], InterfaceType::Spider->iletilecekCerezler());
    }

    /**
     * CİHAZ YAVAŞSA VAZGEÇİLMEZ — ama yanlış parolada ısrar edilmez.
     *
     * Gömülü cihazların TLS'i ağır: TCP bağlantısı hızlı kurulsa da el
     * sıkışma saniyeler sürebilir. Yoğun anda sınır aşılır; parola da yol da
     * doğruyken kullanıcı hata görmemeli, cihaz yalnızca yavaştır.
     *
     * Yanıt GELDİYSE tekrar denenmez: yanlış parolayı üç kez göndermek,
     * hesabı kilitleyebilir — zaman aşımından pahalı bir sonuç.
     */
    #[Test]
    public function baglanti_hatasi_yeniden_denenir_yanit_hatasi_denenmez(): void
    {
        Http::fake([
            'kvm.ornek.test/auth.asp' => Http::sequence()
                ->pushFailedConnection()
                ->push('', 302, ['Set-Cookie' => 'pp_session_id=IKINCI-DENEME; path=/']),
        ]);

        $this->assertSame(
            'pp_session_id=IKINCI-DENEME',
            app(InterfaceLogin::class)->ac(InterfaceType::Spider, $this->kayit(), 'kvm.ornek.test'),
        );

        Http::assertSentCount(2);
    }

    /**
     * YANIT GELDİYSE TEK DENEME.
     *
     * Yanlış parolayı üç kez göndermek hesabı kilitleyebilir; kilitlenen bir
     * hesap, zaman aşımından pahalı bir sonuçtur. Cihaz yanıt verdiyse
     * "ulaşılamıyor" değil "kabul etmedi" demektir ve tekrar denemek o
     * cevabı değiştirmez.
     */
    #[Test]
    public function yanit_hatasinda_kimlik_tekrar_denenmez(): void
    {
        Http::fake(['kvm.ornek.test/auth.asp' => Http::response('', 403)]);

        try {
            app(InterfaceLogin::class)->ac(InterfaceType::Spider, $this->kayit(), 'kvm.ornek.test');
            $this->fail('Çerez gelmediği hâlde oturum açılmış sayıldı.');
        } catch (RuntimeException) {
            /* beklenen */
        }

        Http::assertSentCount(1);
    }
}
