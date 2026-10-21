<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\DeviceInterface\InterfaceLogin;
use App\Services\DeviceInterface\InterfaceType;
use App\Services\Portal\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * MAILCOW YÖNETİM ARAYÜZÜ — oturumu SUNUCU açar.
 *
 * mailcow 2026-07'de giriş akışı:
 *
 *     GET  /admin/   → 200, Set-Cookie: MCSESSID=…
 *     POST /admin/   login_user=…&pass_user=…
 *                    → 302, Location: /admin/dashboard
 *
 * YOL ÖNEMLİ: giriş formu BULUNDUĞU SAYFAYA
 * gönderiyor (`<form method="post">`, action yok) ve yönetici girişini işleyen
 * tetikleyici yalnızca /admin altında yükleniyor. Köke gönderilen aynı istek
 * 200 dönüyor, oturum açılmıyor ve mailcow günlüğüne "Invalid password for
 * admin" düşüyor — parola DOĞRU olsa bile. Nitekim cihazın kendi
 * `verify_hash()` çağrısı aynı parolayı geçerli buluyordu.
 */
final class MailcowInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'name' => 'Panel',
            'access_type' => AccessType::Panel,
            'platform' => 'Mailcow',
            'host' => 'posta.ornek.test',
            'port' => null,
            'url' => 'https://posta.ornek.test',
            'username' => 'admin',
            'password' => 'YONETICI-PAROLASI',
            ...$ustune,
        ]);
    }

    /**
     * CİHAZ AİLESİNDEN TANINIR.
     *
     * Ötekilerde sabit bir giriş adresi var; mailcow'un formu bulunduğu
     * sayfaya gönderdiği için adreste ayırt edici bir iz yok. Kayıtta zaten
     * yazılı olan "platform" alanı doğru soruyu cevaplıyor: bu cihaz nedir.
     */
    #[Test]
    public function platform_alanindan_taninir(): void
    {
        config(['remote.arayuz.turler' => ['mailcow']]);

        $this->assertSame(InterfaceType::Mailcow, InterfaceType::icin($this->kayit()));
        $this->assertNull(InterfaceType::icin($this->kayit(['platform' => 'Debian 13'])));
    }

    /** Vekil açıkken form yolu devreye girmez; ikisi aynı kaydı sahiplenemez. */
    #[Test]
    public function vekil_acikken_form_yolu_devre_disi(): void
    {
        config(['remote.arayuz.turler' => ['mailcow']]);

        $this->assertFalse(PortalLogin::hazirMi($this->kayit([
            'login_url' => 'https://posta.ornek.test/admin/',
            'login_username_field' => 'login_user',
            'login_password_field' => 'pass_user',
        ])));
    }

    /** Giriş SUNUCUDA ve YÖNETİM KÖKÜNDE yapılır; oturum çerezi panelde kalır. */
    #[Test]
    public function oturum_yonetim_kokunde_acilir(): void
    {
        Http::fake([
            'posta.ornek.test/admin/' => Http::sequence()
                ->push('', 200, ['Set-Cookie' => 'MCSESSID=ILKOTURUM; path=/'])
                ->push('', 302, ['Location' => '/admin/dashboard', 'Set-Cookie' => 'MCSESSID=GIRISLI; path=/']),
        ]);

        $cerez = app(InterfaceLogin::class)->ac(InterfaceType::Mailcow, $this->kayit(), 'posta.ornek.test');

        /* İkinci adımın çerezi kazanır: mailcow girişte oturumu tazeliyor. */
        $this->assertSame('MCSESSID=GIRISLI', $cerez);

        Http::assertSent(function ($istek): bool {
            if ($istek->method() !== 'POST') {
                return true;
            }

            return str_ends_with($istek->url(), '/admin/')
                && $istek['login_user'] === 'admin'
                && $istek['pass_user'] === 'YONETICI-PAROLASI';
        });
    }

    /**
     * YÖNLENDİRME YOKSA GİRİŞ DE YOK.
     *
     * Yanlış parolada mailcow 200 dönüp giriş sayfasını yeniden basıyor;
     * durum koduna bakan kod başarısız girişi başarılı sayardı.
     */
    #[Test]
    public function yonlendirme_yoksa_oturum_acilmaz(): void
    {
        Http::fake([
            'posta.ornek.test/admin/' => Http::response('', 200, ['Set-Cookie' => 'MCSESSID=ABC; path=/']),
        ]);

        $this->expectException(RuntimeException::class);

        app(InterfaceLogin::class)->ac(InterfaceType::Mailcow, $this->kayit(), 'posta.ornek.test');
    }

    /** Varlıklar kökte, iniş panoda: cihazın 302'si birebir oraya işaret ediyor. */
    #[Test]
    public function yollar_cihazin_kendi_yollari(): void
    {
        $this->assertSame('/', InterfaceType::Mailcow->cihazYolu());
        $this->assertSame('/admin/dashboard', InterfaceType::Mailcow->inisYolu());
        $this->assertTrue(InterfaceType::Mailcow->sunucudaGiris());
        $this->assertNull(InterfaceType::Mailcow->gizliBasligi());
        $this->assertTrue(InterfaceType::Mailcow->kullaniciAdiGerekir());
    }

    /**
     * OTURUM TARAYICININ KİMLİĞİYLE AÇILIR.
     *
     * mailcow oturumu açarken tarayıcı kimliğini saklıyor ve sonraki her
     * istekte karşılaştırıyor (inc/sessions.inc.php: SESS_REMOTE_UA). Girişi
     * panel yaptığı için orada PANELİN istemcisi yazılıyordu; kullanıcı aynı
     * oturumu kendi tarayıcısıyla sürdürünce mailcow her form isteğinde
     * "Form token invalid: User-Agent validation error" diyordu.
     *
     * Arayüz çalışıyor GÖRÜNÜYORDU: okuma istekleri geçiyor, form gönderen
     * her işlem sessizce reddediliyor.
     */
    #[Test]
    public function giris_kullanicinin_tarayici_kimligiyle_yapilir(): void
    {
        $tarayici = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/149.0.0.0';

        /* Oturumu açan istek kullanıcının tarayıcısından gelir. */
        $this->app['request'] = Request::create('/erisim', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => $tarayici,
        ]);

        Http::fake([
            'posta.ornek.test/admin/' => Http::sequence()
                ->push('', 200, ['Set-Cookie' => 'MCSESSID=ILK; path=/'])
                ->push('', 302, ['Location' => '/admin/dashboard', 'Set-Cookie' => 'MCSESSID=GIRISLI; path=/']),
        ]);

        app(InterfaceLogin::class)->ac(InterfaceType::Mailcow, $this->kayit(), 'posta.ornek.test');

        Http::assertSent(fn ($istek): bool => $istek->header('User-Agent')[0] === $tarayici);
    }
}
