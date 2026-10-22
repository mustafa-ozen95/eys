<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\User;
use App\Services\DeviceInterface\InterfaceSession;
use App\Services\DeviceInterface\InterfaceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HER ARAYÜZ KENDİ PORTUNDA AÇILIR.
 *
 * Ortak portta (9443) oturumu ÇEREZ taşıyordu ve çerez sekmeye değil
 * TARAYICIYA ait: aynı anda iki arayüz açan kullanıcının istekleri son açılana
 * gidiyordu. Aynı türden iki cihazda (ör. iki "Plesk Panel": srv-a ve srv-b)
 * ayıracak hiçbir ipucu kalmıyordu.
 *
 * YOL ÖNEKİ DENENDİ VE YETMEDİ. Önek altında göreli adresler doğru çözülüyor
 * ama cihazların MUTLAK adresleri öneğin dışına çıkıyor — Plesk'in kendi
 * menüsü "/admin/domain/list?context=domains" diyor ve sayfa geçişi öneği
 * düşürür.
 *
 * PORT ÖYLE BİR KAÇIŞ BIRAKMIYOR: mutlak adres de aynı portta kalır.
 *
 * Örnek (iki Plesk ile, çerez bilerek srv-b'yi gösterirken):
 *     birinci port /admin/domain/list → 200, içerik srv-a
 *     ikinci port  /admin/domain/list → 200, içerik srv-b
 */
final class InterfacePortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'remote.interface.domain' => 'eys.ornek.test:9443',
            'remote.interface.types' => ['plesk'],
            'remote.interface.port_start' => 9450,
            'remote.interface.port_end' => 9452,
        ]);

        Http::fake(['*' => Http::response('', 303, ['Set-Cookie' => 'PLESKSESSID=abc; path=/'])]);
    }

    private function plesk(string $konak): Credential
    {
        return Credential::factory()->create([
            'name' => 'Plesk Panel',
            'access_type' => AccessType::Web,
            'host' => $konak,
            'port' => 8443,
            'login_url' => 'https://'.$konak.':8443/login_up.php',
            'username' => 'admin',
            'password' => 'parola',
        ]);
    }

    /** İki arayüz iki ayrı port alır. */
    #[Test]
    public function her_oturum_kendi_portunu_alir(): void
    {
        $kullanici = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        $bir = $oturumlar->ac($this->plesk('10.0.0.1'), $kullanici->id);
        $iki = $oturumlar->ac($this->plesk('10.0.0.2'), $kullanici->id);

        $birinci = (int) $oturumlar->bul($bir['token'])['port'];
        $ikinci = (int) $oturumlar->bul($iki['token'])['port'];

        $this->assertGreaterThanOrEqual(9450, $birinci);
        $this->assertNotSame($birinci, $ikinci, 'İki arayüz aynı porta düşerse çakışma geri gelir.');
    }

    /**
     * HER AÇILIŞ KENDİ PORTUNU ALIR.
     *
     * Aynı kaydın portu korunmaz (adres değişmesin diye bile); her açılış
     * yeni bir port alır, sıralı olması da gerekmez.
     *
     * Havuzu tüketmiyor: kapanan oturumun portu havuza dönüyor, ölü kalan da
     * geri alınıyor.
     */
    #[Test]
    public function her_acilis_yeni_port_alir(): void
    {
        $kullanici = User::factory()->admin()->create();
        $kayit = $this->plesk('10.0.0.1');
        $oturumlar = app(InterfaceSession::class);

        $ilk = (int) $oturumlar->bul($oturumlar->ac($kayit, $kullanici->id)['token'])['port'];
        $ikinci = (int) $oturumlar->bul($oturumlar->ac($kayit, $kullanici->id)['token'])['port'];

        $this->assertNotSame($ilk, $ikinci);
    }

    /** Adres oturumun portundan kurulur. */
    #[Test]
    public function adres_portludur(): void
    {
        $kullanici = User::factory()->admin()->create();
        $kayit = $this->plesk('10.0.0.1');
        $oturum = app(InterfaceSession::class)->ac($kayit, $kullanici->id);
        $port = (int) app(InterfaceSession::class)->bul($oturum['token'])['port'];

        $this->assertSame(
            'https://eys.ornek.test:'.$port.'/',
            InterfaceSession::pageUrl($oturum['token'], InterfaceType::Plesk, $kayit, $port),
        );
    }

    /**
     * OTURUMU PORT SÖYLER — ÇEREZ BAŞKASINI GÖSTERSE BİLE.
     *
     * Çakışmanın kaynağı buydu: çerez son açılan arayüzü gösteriyor ve eski
     * sekmenin istekleri oraya gidiyordu.
     */
    #[Test]
    public function bekci_oturumu_porttan_bulur(): void
    {
        $kullanici = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        $birinci = $oturumlar->ac($this->plesk('10.0.0.1'), $kullanici->id);
        $sonuncu = $oturumlar->ac($this->plesk('10.0.0.2'), $kullanici->id);

        $port = (int) $oturumlar->bul($birinci['token'])['port'];

        /* Çerez SON açılanı gösteriyor; port ilkini. */
        $this->withCookies([InterfaceSession::COOKIE => $sonuncu['token']])
            ->withHeader('X-Original-Uri', '/admin/domain/list?context=domains')
            /* Port adresin parçası: istek o portta yapılmış gibi kurulur. */
            ->get('http://eys.ornek.test:'.$port.route('ajax.interface.guard', [], false))
            ->assertNoContent()
            ->assertHeader('X-Eys-Target', '10.0.0.1:8443');
    }

    /**
     * HAVUZ DOLUNCA ARAYÜZ ORTAK PORTTA AÇILIR.
     *
     * Yer kalmadı diye arayüzü hiç açmamak, çakışma riskinden kötüdür: o
     * durumda oturumu yine çerez taşır ve davranış eski hâline döner.
     */
    #[Test]
    public function havuz_dolunca_ortak_porta_duser(): void
    {
        $kullanici = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        /* Havuz üç port: dördüncü oturum portsuz kalmalı. */
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $konak) {
            $oturumlar->ac($this->plesk($konak), $kullanici->id);
        }

        $tasan = $oturumlar->ac($this->plesk('10.0.0.4'), $kullanici->id);

        $this->assertSame(0, (int) $oturumlar->bul($tasan['token'])['port']);
        $this->assertSame(
            'https://eys.ornek.test:9443/',
            InterfaceSession::pageUrl($tasan['token'], InterfaceType::Plesk, null, 0),
        );
    }

    /**
     * PORT OTURUMU SEÇER, KİMLİĞİ ÇEREZ KANITLAR.
     *
     * Port dar bir aralıktan seçilir; tahmin edilebilir ve adres çubuğunda görünür.
     * Tek başına yeterli sayılınca giriş yapmamış ve yetkisi olmayan biri de o
     * adrese gelip cihaza ulaşabilirdi. Çerez ise gizli: HttpOnly ve konak-özel.
     *
     * Aranan şey çerezin BU oturumun jetonu olması değil — aynı tarayıcıda
     * birden çok arayüz açık olabiliyor ve çerez yalnızca en son açılanı
     * gösteriyor — aynı KİŞİYE ait olması.
     */
    #[Test]
    public function port_tek_basina_yetmez(): void
    {
        $sahibi = User::factory()->admin()->create();
        $baskasi = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        $oturum = $oturumlar->ac($this->plesk('10.0.0.1'), $sahibi->id);
        $onunki = $oturumlar->ac($this->plesk('10.0.0.2'), $baskasi->id);
        $port = (int) $oturumlar->bul($oturum['token'])['port'];

        $adres = 'http://eys.ornek.test:'.$port.route('ajax.interface.guard', [], false);

        /* Çerezsiz: giriş yapmamış biri. */
        $this->withHeader('X-Original-Uri', '/admin/home')->get($adres)->assertForbidden();

        /* Başkasının oturumu: aynı porta gelse de geçmez. */
        $this->flushHeaders();
        $this->withCookies([InterfaceSession::COOKIE => $onunki['token']])
            ->withHeader('X-Original-Uri', '/admin/home')
            ->get($adres)
            ->assertForbidden();

        /* Sahibi: geçer. */
        $this->flushHeaders();
        $this->withCookies([InterfaceSession::COOKIE => $oturum['token']])
            ->withHeader('X-Original-Uri', '/admin/home')
            ->get($adres)
            ->assertNoContent()
            ->assertHeader('X-Eys-Target', '10.0.0.1:8443');
    }

    /**
     * AÇILIŞ BETİĞİ DE AYNI KURALA TABİ.
     *
     * Betik oturumun jetonunu adresinde taşıyor; kimliği yine çerez kanıtlar.
     * "Adres ile çerez AYNI oturum olmalı" kuralı yetmez: birden çok oturum
     * açıkken kendi betiği bile reddedilirdi (iLO konsolunda 403).
     */
    #[Test]
    public function acilis_betigi_sahibine_acilir(): void
    {
        $sahibi = User::factory()->admin()->create();
        $baskasi = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        $eski = $oturumlar->ac($this->plesk('10.0.0.1'), $sahibi->id);
        /* Çerez SON açılanı gösteriyor; betik eski oturumun jetonunu taşıyor. */
        $yeni = $oturumlar->ac($this->plesk('10.0.0.2'), $sahibi->id);

        $this->withCookies([InterfaceSession::COOKIE => $yeni['token']])
            ->get('/device-session/'.$eski['token'].'/boot.js')
            ->assertOk();

        $this->flushHeaders();
        $this->withCookies([InterfaceSession::COOKIE => $oturumlar->ac($this->plesk('10.0.0.3'), $baskasi->id)['token']])
            ->get('/device-session/'.$eski['token'].'/boot.js')
            ->assertForbidden();
    }
}
