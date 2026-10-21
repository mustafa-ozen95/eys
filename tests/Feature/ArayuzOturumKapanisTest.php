<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\KonsolOturumu;
use App\Models\User;
use App\Services\CihazArayuzu\ArayuzOturumu;
use App\Services\CihazArayuzu\ArayuzTuru;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cihazda açılan oturum CİHAZDA da kapanır.
 *
 * Oturumu panel açıyor (Plesk, iLO, vSphere), dolayısıyla kapatmak da ona
 * düşüyor. Kapatan olmayınca oturum cihazın kendi zaman aşımını bekliyor ve o
 * süre boyunca listede duruyor; art arda birkaç açılışta cihazın oturum
 * listesi doluyor (iLO'da panelin adresinden açılmış oturumlar birikir ve
 * sonunda cihaz yeni girişi reddeder).
 *
 * Kilitlenen şey: çerezin kapatabilmek için dayanıklı yerde durması, kapanışta
 * cihaza çıkış çağrısının GİTMESİ, çerezin sonra SİLİNMESİ ve "ayrıldım"
 * haberinin oturumu tek başına kapatmaması (sayfa yenilemesi de aynı olayı
 * tetikliyor).
 */
final class ArayuzOturumKapanisTest extends TestCase
{
    use RefreshDatabase;

    private function iloKaydi(): Credential
    {
        return Credential::factory()->create([
            'access_type' => AccessType::Panel,
            'operating_system' => OperatingSystem::Bmc,
            'host' => '10.0.0.55',
            'port' => null,
            'url' => null,
            'login_url' => null,
            'username' => 'Administrator',
            'password' => 'ILO-PAROLASI',
        ]);
    }

    private function turleriAc(): void
    {
        config()->set('remote.arayuz.turler', ['esxi', 'vami', 'plesk', 'ilo']);
    }

    /** Cihazın giriş yanıtı: oturum çerezi verir. */
    private function girisYanitlari(): void
    {
        Http::fake([
            '*/json/login_session' => Http::response(
                ['session_key' => 'ABC123'],
                200,
                ['Set-Cookie' => 'sessionKey=ABC123; Path=/; HttpOnly'],
            ),
        ]);
    }

    #[Test]
    public function cihaz_cerezi_satirda_saklanir(): void
    {
        $this->turleriAc();
        $this->girisYanitlari();

        $user = User::factory()->create();
        $credential = $this->iloKaydi();

        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);

        $satir = KonsolOturumu::query()->where('token', $oturum['token'])->firstOrFail();

        /*
         * Önbellekteki kayıt oturumun ömrü dolunca siliniyor; çerez yalnızca
         * orada dursaydı temizlik sırası geldiğinde cihazda çıkış yapacak bir
         * şey kalmazdı.
         */
        $this->assertSame('sessionKey=ABC123', $satir->cihaz_cerezi);
    }

    #[Test]
    public function kapanista_cihaza_cikis_gider_ve_cerez_silinir(): void
    {
        $this->turleriAc();
        $this->girisYanitlari();

        $user = User::factory()->create();
        $credential = $this->iloKaydi();

        $oturumlar = app(ArayuzOturumu::class);
        $oturum = $oturumlar->ac($credential, (int) $user->id);
        $satir = KonsolOturumu::query()->where('token', $oturum['token'])->firstOrFail();

        $oturumlar->kapat($satir, 'kullanici');

        Http::assertSent(fn ($istek) => str_contains($istek->url(), '/json/login_session')
            && ($istek->data()['method'] ?? null) === 'logout'
            && $istek->hasHeader('Cookie', 'sessionKey=ABC123'));

        $satir->refresh();

        $this->assertNotNull($satir->closed_at);
        $this->assertSame('kullanici', $satir->closed_reason);
        $this->assertNull($satir->cihaz_cerezi, 'Kapanmış oturumun anahtarı elimizde durmamalı.');
    }

    #[Test]
    public function ayrildim_haberi_oturumu_kapatmaz_sessiz_sayar(): void
    {
        $this->turleriAc();
        $this->girisYanitlari();

        $user = User::factory()->create();
        $credential = $this->iloKaydi();

        $oturumlar = app(ArayuzOturumu::class);
        $oturum = $oturumlar->ac($credential, (int) $user->id);

        $this->withCookies([ArayuzOturumu::COOKIE => $oturum['token']])
            ->post('/cihaz-oturum/ayril')
            ->assertNoContent();

        $satir = KonsolOturumu::query()->where('token', $oturum['token'])->firstOrFail();

        /*
         * KAPANMAZ: aynı olay sayfa yenilenirken de tetikleniyor ve tek bir F5
         * oturumu kapatmamalı. Yalnızca "sesi kesildi" işareti konur.
         */
        $this->assertNull($satir->closed_at);
        $this->assertTrue(
            $satir->last_seen_at->lt(now()->subSeconds((int) config('remote.session.ttl'))),
            'Son ses zamanı geriye alınmalı ki temizlik oturumu görsün.',
        );
    }

    #[Test]
    public function temizlik_sessiz_kalan_arayuz_oturumunu_cihazda_da_kapatir(): void
    {
        $this->turleriAc();
        $this->girisYanitlari();

        $user = User::factory()->create();
        $credential = $this->iloKaydi();

        $oturumlar = app(ArayuzOturumu::class);
        $oturum = $oturumlar->ac($credential, (int) $user->id);
        $oturumlar->ayrildi($oturumlar->bul($oturum['token']));

        Artisan::call('erisim:konsol-temizle');

        Http::assertSent(fn ($istek) => ($istek->data()['method'] ?? null) === 'logout');

        $satir = KonsolOturumu::query()->where('token', $oturum['token'])->firstOrFail();

        $this->assertNotNull($satir->closed_at);
        $this->assertSame('dustu', $satir->closed_reason);
    }

    #[Test]
    public function cihaz_cerezi_yalnizca_turun_saydiklari_tarayiciya_iner(): void
    {
        /*
         * iLO oturumun varlığını sunucuya sormuyor, tarayıcıda yokluyor
         * (index.html: getCookie("sessionKey") yoksa giriş ekranı). O yüzden
         * çerez bizim kökenimizde tarayıcıya da bırakılır — ama YALNIZCA türün
         * açıkça saydığı adlar; cihazın yolladığı her çerez değil.
         */
        $this->assertSame(['sessionKey'], ArayuzTuru::Ilo->tarayiciCerezleri());
        $this->assertSame([], ArayuzTuru::Plesk->tarayiciCerezleri());
        $this->assertSame([], ArayuzTuru::Esxi->tarayiciCerezleri());

        /* Şifrelenmemeli: cihazın betiği kendi değerini tanıyamaz. */
        $this->assertContains('sessionKey', ArayuzTuru::sifrelenmeyenCerezler());
        $this->assertContains(ArayuzOturumu::KONAK_COOKIE, ArayuzTuru::sifrelenmeyenCerezler());
    }
}
