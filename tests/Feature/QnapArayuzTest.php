<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\CihazArayuzu\ArayuzGirisi;
use App\Services\CihazArayuzu\ArayuzTuru;
use App\Services\Portal\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * QNAP (QTS) YÖNETİM ARAYÜZÜ — oturumu SUNUCU açar.
 *
 * Giriş ucu ÇEREZ DEĞİL XML döndürüyor: `authLogin.cgi` 200 veriyor,
 * gövdede `<authPassed>1</authPassed>` ve `<authSid>` var, Set-Cookie
 * başlığı YOK. Çerezi normalde arayüzün kendi betiği yazıyor; vekilde onu
 * panel kuruyor ve her isteğe ekliyor.
 *
 * Böylece parola kullanıcının tarayıcısına hiç inmiyor — vCenter ve Plesk'te
 * olduğu gibi.
 */
final class QnapArayuzTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'name' => 'QNAP Web Yönetim',
            'access_type' => AccessType::Panel,
            'host' => '192.0.2.242',
            'port' => null,
            'url' => 'https://192.0.2.242/',
            'login_url' => 'https://192.0.2.242/cgi-bin/authLogin.cgi',
            'username' => 'backup',
            'password' => 'QNAP-PAROLASI',
            ...$ustune,
        ]);
    }

    #[Test]
    public function giris_adresinden_taninir(): void
    {
        config(['remote.arayuz.turler' => ['qnap']]);

        $this->assertSame(ArayuzTuru::Qnap, ArayuzTuru::icin($this->kayit()));
    }

    /** Giriş ucu yazılmamış kayıt bu arayüze girmez. */
    #[Test]
    public function giris_ucu_yoksa_taninmaz(): void
    {
        config(['remote.arayuz.turler' => ['qnap']]);

        $this->assertNull(ArayuzTuru::icin($this->kayit(['login_url' => null])));
    }

    /** Vekil açıkken form yolu devrede olmaz. */
    #[Test]
    public function vekil_acikken_form_yolu_devre_disi(): void
    {
        config(['remote.arayuz.turler' => ['qnap']]);

        $this->assertFalse(PortalLogin::hazirMi($this->kayit()));
    }

    /**
     * PAROLA BASE64 GİDER ve oturum çerezi XML'den kurulur.
     *
     * Cihazın kendi betiği de öyle gönderiyor (qos-core-login.js:
     * `pwd = ezEncode(utf16to8(parola))`). Bu bir şifreleme değil, cihazın
     * beklediği biçim.
     */
    #[Test]
    public function giris_sunucuda_yapilir_ve_cerez_kurulur(): void
    {
        Http::fake([
            '192.0.2.242/cgi-bin/authLogin.cgi' => Http::response(
                '<?xml version="1.0" encoding="UTF-8" ?><QDocRoot version="1.0">'
                .'<authPassed><![CDATA[1]]></authPassed><authSid><![CDATA[abcd1234]]></authSid>'
                .'<username><![CDATA[backup]]></username></QDocRoot>',
                200,
            ),
        ]);

        $cerez = app(ArayuzGirisi::class)->ac(ArayuzTuru::Qnap, $this->kayit(), '192.0.2.242');

        $this->assertStringContainsString('NAS_SID=abcd1234', $cerez);
        $this->assertStringContainsString('NAS_USER=backup', $cerez);

        Http::assertSent(fn ($istek): bool => $istek['user'] === 'backup'
            && $istek['pwd'] === base64_encode('QNAP-PAROLASI'));
    }

    /**
     * BAŞARISIZ GİRİŞ 200 İLE DÖNÜYOR.
     *
     * Durum koduna bakmak yanlış parolayı başarılı sayardı; karar gövdedeki
     * `authPassed` alanına ait.
     */
    #[Test]
    public function auth_passed_sifirsa_oturum_acilmaz(): void
    {
        Http::fake([
            '192.0.2.242/cgi-bin/authLogin.cgi' => Http::response(
                '<QDocRoot><authPassed><![CDATA[0]]></authPassed></QDocRoot>',
                200,
            ),
        ]);

        $this->expectException(RuntimeException::class);

        app(ArayuzGirisi::class)->ac(ArayuzTuru::Qnap, $this->kayit(), '192.0.2.242');
    }

    /**
     * OTURUM ÇEREZİ TARAYICIYA DA VERİLİR.
     *
     * QTS masaüstü betiği NAS_SID'i `document.cookie`den okuyup API
     * çağrılarını ona göre kuruyor. Çerez yalnızca sunucuda kalsaydı sayfa,
     * vekil isteklere onu eklese bile giriş ekranına dönerdi (iLO'da da aynı
     * davranış var). Parola yine inmiyor.
     */
    #[Test]
    public function oturum_cerezi_tarayiciya_da_verilir(): void
    {
        $this->assertSame(['NAS_SID', 'NAS_USER', 'home'], ArayuzTuru::Qnap->tarayiciCerezleri());
        $this->assertSame([], ArayuzTuru::Plesk->tarayiciCerezleri());
    }

    /** Giriş sonrası QTS masaüstüne gidilir; kök yalnızca giriş ekranını çizer. */
    #[Test]
    public function inis_qts_masaustudur(): void
    {
        $this->assertSame('/', ArayuzTuru::Qnap->cihazYolu());
        $this->assertSame('/cgi-bin/main.html', ArayuzTuru::Qnap->inisYolu());
        $this->assertTrue(ArayuzTuru::Qnap->sunucudaGiris());
    }
}
