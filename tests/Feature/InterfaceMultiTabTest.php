<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\User;
use App\Services\DeviceInterface\InterfaceSession;
use App\Services\DeviceInterface\InterfaceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * İKİ ARAYÜZ İKİ SEKMEDE: yol bir türe özgüyse çerez değil YOL karar verir.
 *
 * Vekil kökeninde oturumu ÇEREZ taşıyor ve çerez sekmeye değil TARAYICIYA
 * ait. Kullanıcı iki arayüzü iki sekmede açtığında son açılan kazanıyor ve
 * eski sekmenin istekleri yanlış cihaza gidiyor.
 *
 * Örnek: Plesk ve vCenter aynı anda açıkken vSphere'in "New Virtual Machine"
 * sihirbazı `/apigw` isteğini PLESK'e gönderir ve cevap olarak Plesk'in 404
 * sayfasını alır (ekranda "… Page Not Found").
 *
 * `/sdk` bu tuzağa düşmüyor: vSphere orada hedefi `x-vsphere-proxy`
 * başlığında söylüyor. `/apigw` ise ne o başlığı ne de Referer'ı taşıyor —
 * erişim günlüğünde referer alanı boş. Geriye tek ipucu yolun kendisi
 * kalıyor ve o yol yalnızca vSphere'de var.
 */
final class InterfaceMultiTabTest extends TestCase
{
    use RefreshDatabase;

    /*
     * vSphere ailesinden ESXi seçildi: girişi TARAYICIDA yapılıyor, yani
     * oturum açmak için cihaza hiç istek gitmiyor. vCenter'ınki çok adımlı
     * bir SAML akışı ve burada ölçtüğümüz şey giriş değil, OTURUM SEÇİMİ.
     */
    private function esxi(): Credential
    {
        return Credential::factory()->create([
            'name' => 'ESXi',
            'access_type' => AccessType::Esxi,
            'operating_system' => OperatingSystem::Esxi,
            'host' => '10.0.0.8',
            'port' => 443,
            'username' => 'root',
            'password' => 'parola',
        ]);
    }

    private function plesk(): Credential
    {
        return Credential::factory()->create([
            'name' => 'Plesk Panel',
            'access_type' => AccessType::Web,
            'host' => '10.0.0.9',
            'port' => 8443,
            'login_url' => 'https://10.0.0.9:8443/login_up.php',
            'username' => 'admin',
            'password' => 'parola',
        ]);
    }

    /**
     * SON AÇILAN PLESK OLSA BİLE /apigw vCENTER OTURUMUNA GİDER.
     *
     * Çerez Plesk'i gösteriyor; yol vSphere'e ait olduğu için bekçi
     * kullanıcının açık vSphere oturumunu buluyor.
     */
    #[Test]
    public function apigw_istegi_vsphere_oturumuna_gider(): void
    {
        config(['remote.interface.types' => ['esxi', 'plesk']]);

        Http::fake([
            '*' => Http::response('', 303, ['Set-Cookie' => 'PLESKSESSID=abc; path=/']),
        ]);

        $kullanici = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        $esxi = $oturumlar->ac($this->esxi(), $kullanici->id);
        $plesk = $oturumlar->ac($this->plesk(), $kullanici->id);

        /* Son açılan Plesk: tarayıcının çerezi onu gösteriyor. */
        $this->assertSame(
            InterfaceType::Plesk->value,
            $oturumlar->bul($plesk['token'])['tur'],
        );

        $bulunan = $oturumlar->turIcin(
            $kullanici->id,
            [InterfaceType::Vcenter, InterfaceType::Esxi, InterfaceType::Vami],
        );

        $this->assertNotNull($bulunan);
        $this->assertSame($esxi['token'], $bulunan['token']);
    }

    /** Başka kullanıcının oturumuna geçilmez. */
    #[Test]
    public function baska_kullanicinin_oturumu_bulunmaz(): void
    {
        config(['remote.interface.types' => ['esxi']]);

        Http::fake(['*' => Http::response('', 303, ['Set-Cookie' => 'x=1; path=/'])]);

        $sahibi = User::factory()->admin()->create();
        $baskasi = User::factory()->admin()->create();

        app(InterfaceSession::class)->ac($this->esxi(), $sahibi->id);

        $this->assertNull(app(InterfaceSession::class)->turIcin($baskasi->id, [InterfaceType::Esxi]));
    }

    /** vSphere ailesi doğru sayılır; ötekiler dışarıda kalır. */
    #[Test]
    public function vsphere_ailesi_dogru_sayilir(): void
    {
        foreach ([InterfaceType::Esxi, InterfaceType::Vcenter, InterfaceType::Vami] as $tur) {
            $this->assertTrue($tur->vsphereMi(), $tur->value.' vSphere ailesinden sayılmalı.');
        }

        foreach ([InterfaceType::Plesk, InterfaceType::Ilo, InterfaceType::Qnap,
            InterfaceType::DirectAdmin, InterfaceType::Spider, InterfaceType::Rspamd, InterfaceType::Mailcow] as $tur) {
            $this->assertFalse($tur->vsphereMi(), $tur->value.' vSphere ailesinden sayılmamalı.');
        }
    }

    private function ilo(): Credential
    {
        return Credential::factory()->create([
            'name' => 'iLO',
            'access_type' => AccessType::Panel,
            'operating_system' => OperatingSystem::Bmc,
            'host' => '10.0.0.7',
            'port' => 443,
            'username' => 'administrator',
            'password' => 'parola',
        ]);
    }

    /**
     * YOLUN SAHİBİ ÖLÇÜLEREK BELİRLENDİ.
     *
     * Listeye yol eklemek "bu yol başka hiçbir arayüzde yok" demektir; her
     * biri cihazda ölçüldü:
     *
     *   /ui/usersession/sessionPing → vCenter 200 | iLO 404 (0 bayt)
     *   /wss/ircport                → iLO 101     | vCenter 404 (0 bayt)
     *   /cgi-bin/authLogin.cgi      → QTS'in sabit giriş ucu
     *
     * Sahipsiz yollarda (ortak varlıklar) karar yine çerezdedir: orada bir
     * iddiada bulunamayız.
     */
    #[Test]
    public function yolun_sahibi_dogru_aileyi_gosterir(): void
    {
        $vsphere = [InterfaceType::Vcenter, InterfaceType::Esxi, InterfaceType::Vami];

        $this->assertSame($vsphere, InterfaceType::yolunSahipleri('/apigw'));
        $this->assertSame($vsphere, InterfaceType::yolunSahipleri('/ui/usersession/sessionPing'));
        $this->assertSame($vsphere, InterfaceType::yolunSahipleri('/webconsole-ticket/17/abc'));
        $this->assertSame([InterfaceType::Ilo], InterfaceType::yolunSahipleri('/wss/ircport'));
        $this->assertSame([InterfaceType::Ilo], InterfaceType::yolunSahipleri('/json/login_session'));
        $this->assertSame([InterfaceType::Qnap], InterfaceType::yolunSahipleri('/cgi-bin/authLogin.cgi'));

        $this->assertSame([], InterfaceType::yolunSahipleri('/js/uygulama.js'));
        $this->assertSame([], InterfaceType::yolunSahipleri('/favicon.ico'));
    }

    /**
     * iLO KONSOLU KENDİ OTURUMUNA GİDER.
     *
     * Kullanıcı iLO'yu açtıktan sonra başka bir arayüze geçince konsolun
     * `/wss/ircport` isteği o cihaza gidip 400 alıyordu — oysa iLO'nun kendisi
     * aynı el sıkışmaya her denemede 101 veriyor.
     */
    #[Test]
    public function ilo_konsolu_kendi_oturumuna_gider(): void
    {
        config(['remote.interface.types' => ['ilo', 'plesk']]);

        Http::fake([
            '10.0.0.7/*' => Http::response('', 200, ['Set-Cookie' => 'sessionKey=ABC; path=/']),
            '*' => Http::response('', 303, ['Set-Cookie' => 'PLESKSESSID=abc; path=/']),
        ]);

        $kullanici = User::factory()->admin()->create();
        $oturumlar = app(InterfaceSession::class);

        $ilo = $oturumlar->ac($this->ilo(), $kullanici->id);

        /* Sonra Plesk açılıyor: tarayıcının çerezi artık onu gösteriyor. */
        $plesk = $oturumlar->ac($this->plesk(), $kullanici->id);

        $this->assertSame(
            InterfaceType::Plesk->value,
            $oturumlar->bul($plesk['token'])['tur'],
        );

        $bulunan = $oturumlar->turIcin(
            $kullanici->id,
            InterfaceType::yolunSahipleri('/wss/ircport'),
        );

        $this->assertNotNull($bulunan);
        $this->assertSame($ilo['token'], $bulunan['token']);
    }

    /**
     * SAHİPLİK LİSTESİ KÖK YOLU LİSTESİ DEĞİLDİR.
     *
     * İkisi aynı yazılırsa Plesk bozulur: `/cp/` vSphere'in kök yollarında
     * (API geçidi orada, taban eklenmemeli) ama Plesk'in kendi paneli de
     * `/cp/` altında duruyor.
     *
     * İki cihazda:
     *   GET  /cp/home    → Plesk 303 | vCenter 404 (0 bayt)
     *   POST /cp/graphql → Plesk 303 | vCenter 400
     *   GET  /cp/graphql → Plesk 303 | vCenter 404
     *
     * Sahipliği `/cp/graphql`e daraltmak da YETMEZ, çünkü o ucu Plesk de
     * sunuyor. İki cihazda birden var olan bir yol, hiçbirinin "yalnızca
     * bende" diyebileceği bir yol değildir: Plesk'in kendi GraphQL çağrıları
     * açık vCenter oturumuna gidip 400 alır ve panel kendini yenileyip durur.
     */
    #[Test]
    public function cp_yolunun_tamami_plesk_ile_paylasilir(): void
    {
        /* Hiçbiri sahiplenilmez: karar çerezde kalır. */
        $this->assertSame([], InterfaceType::yolunSahipleri('/cp/home'));
        $this->assertSame([], InterfaceType::yolunSahipleri('/cp/'));
        $this->assertSame([], InterfaceType::yolunSahipleri('/cp/graphql'));

        /* Kök yolu listesi ise `/cp/` demeye devam ediyor: taban eklenmesin. */
        $this->assertContains('/cp/', InterfaceType::Vcenter->kokYollari());
    }

    /**
     * SAHİPLENİLEN HER YOL YALNIZCA BİR AİLEDE CEVAP VERİYOR.
     *
     * Liste cihazlara karşı tarandı (vCenter, Plesk, iLO, QNAP):
     *
     *   /ui/                   → yalnızca vCenter 200
     *   /apigw                 → yalnızca vCenter 400 (ötekiler 404)
     *   /irc.html              → yalnızca iLO 200
     *   /json/login_session    → yalnızca iLO 200
     *   /cgi-bin/authLogin.cgi → yalnızca QNAP 200
     *
     * Sınama listenin ŞİŞMEDİĞİNİ kilitler: yeni bir yol eklemek, onu da
     * cihazlara karşı ölçmeyi gerektirir.
     */
    #[Test]
    public function sahiplik_listesi_olculmus_yollarla_sinirli(): void
    {
        $this->assertSame(
            ['/sdk', '/ui/', '/websso/', '/apigw', '/webconsole-ticket/'],
            InterfaceType::Vcenter->sahiplendigiYollar(),
        );

        $this->assertSame(['/irc.html', '/wss/', '/json/'], InterfaceType::Ilo->sahiplendigiYollar());
        $this->assertSame(['/cgi-bin/'], InterfaceType::Qnap->sahiplendigiYollar());

        /* Plesk hiçbir yolu sahiplenmez: kendine özgü bir kökü yok. */
        $this->assertSame([], InterfaceType::Plesk->sahiplendigiYollar());
    }

    /** "rest" öneki bir arayüze özgü sayılamaz. */
    #[Test]
    public function rest_yolu_sahipsizdir(): void
    {
        $this->assertSame([], InterfaceType::yolunSahipleri('/rest/appliance/health'));
        $this->assertContains('/rest/', InterfaceType::Vcenter->kokYollari());
    }
}
