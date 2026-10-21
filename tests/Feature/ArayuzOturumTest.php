<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\ActivityAction;
use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\KonsolOturumu;
use App\Models\User;
use App\Services\CihazArayuzu\ArayuzIzleyici;
use App\Services\CihazArayuzu\ArayuzOturumu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ESXi arayüzünün vekille açılması.
 *
 * ESXi'nin oturumu DIŞARIDAN açılamıyor: Host Client her SOAP çağrısına kendi
 * ürettiği VMware-CSRF-Token başlığını koyuyor ve ESXi oturumu girişteki
 * jetona bağlıyor (ayrıntısı PortalLogin'deki "ESXİ NEDEN YOK" notunda). Bu
 * yüzden arayüzün KENDİSİ panelin kökeninden veriliyor; jetonu da çerezi de
 * Host Client kendi üretiyor.
 *
 * Kilitlenen şey: parola sayfa kaynağına yazılmıyor, kimlik ucu tek
 * kullanımlık, hedef isteğin başlığından değil kendi kaydımızdan geliyor ve
 * giriş/çıkış/işlem kayda geçiyor.
 */
final class ArayuzOturumTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'access_type' => AccessType::Esxi,
            'operating_system' => OperatingSystem::Esxi,
            'host' => '10.0.0.44',
            'port' => null,
            'url' => null,
            'login_url' => null,
            'username' => 'root',
            'password' => 'ESXI-PAROLASI',
            ...$ustune,
        ]);
    }

    /** @return array{0: User, 1: CredentialGroup} */
    private function yetkili(array $actions): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();

        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return [$user->fresh(), $group];
    }

    #[Test]
    public function oturum_acilir_ve_kayda_gecer(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $yanit = $this->actingAs($user)
            ->post(route('credentials.arayuz', $credential), ['reason' => 'bakim']);

        $yanit->assertRedirect();

        /*
         * Kimlik vekil kökenine ANLIK jetonla taşınır; çerez burada
         * KONULMAZ (ayrı alan adına zaten gitmez).
         */
        $this->assertMatchesRegularExpression(
            '#/cihaz-oturum/devir/[0-9a-f-]{36}$#',
            $yanit->headers->get('Location') ?? '',
        );
        $this->assertArrayNotHasKey(ArayuzOturumu::COOKIE, $yanit->headers->getCookies());

        $this->assertDatabaseHas(KonsolOturumu::class, [
            'credential_id' => $credential->getKey(),
            'protocol' => AccessType::Esxi->value,
            'closed_at' => null,
        ]);

        $this->assertDatabaseHas(ActivityLog::class, ['action' => ActivityAction::PortalSession->value]);
    }

    /**
     * Oturumu açar ve devri tamamlar; geriye oturum kaydı ile vekil çerezi
     * kalır. Sınamaların çoğu bu noktadan başlıyor.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function devralarakAc(User $user, Credential $credential): array
    {
        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);
        $devir = app(ArayuzOturumu::class)->devirJetonuUret($oturum['token']);

        $yanit = $this->get(route('credentials.arayuz.handoff', ['devir' => $devir]));

        $yanit->assertRedirect();
        $yanit->assertCookie(ArayuzOturumu::COOKIE, $oturum['token']);
        /*
         * KONAK ÇEREZİ ŞİFRESİZ. Onu okuyan taraf cihazın kendi betiği (Host
         * Client hedefi bu çerezden alıyor) ve şifrelenmiş değeri tanımaz;
         * panelin çerezi değil, cihazınki (bkz. bootstrap/app.php).
         */
        $yanit->assertPlainCookie(ArayuzOturumu::KONAK_COOKIE, '10.0.0.44');

        return [$oturum, [ArayuzOturumu::COOKIE => $oturum['token']]];
    }

    /**
     * Devir jetonu TEK KULLANIMLIKTIR.
     *
     * Adres çubuğunda, tarayıcı geçmişinde ya da bir kayıt satırında kalsa
     * bile ikinci kez işe yaramamalı.
     */
    #[Test]
    public function devir_jetonu_tek_kullanimliktir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);
        $devir = app(ArayuzOturumu::class)->devirJetonuUret($oturum['token']);

        $this->get(route('credentials.arayuz.handoff', ['devir' => $devir]))->assertRedirect();
        $this->get(route('credentials.arayuz.handoff', ['devir' => $devir]))->assertStatus(410);
    }

    /**
     * Vekil uçları PANELİN OTURUMUNU İSTEMEZ.
     *
     * Ayrı alan adında panel çerezi zaten gelmiyor; kimlik vekil kökenindeki
     * çerezle taşınıyor. Panele giriş yapmış olmak tek başına yetmez, çerez
     * de yetmez — ikisi AYNI oturumu göstermeli.
     */
    #[Test]
    public function sayfa_panel_oturumu_olmadan_vekil_cereziyle_acilir(): void
    {
        Http::fake(['https://10.0.0.44/ui/' => Http::response('<html><head></head><body>ESXi</body></html>')]);

        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        [$oturum, $cerez] = $this->devralarakAc($user, $credential);

        /* Panele hiç giriş yapmadan, yalnızca vekil çereziyle. */
        $this->withCookies($cerez)->get($oturum['adres'])->assertOk();
    }

    /**
     * Çerezsiz açılmaz — panele giriş yapmış olmak da yetmez.
     *
     * Panelin oturumu bu yolda hiçbir şey ifade etmiyor; vekilin kendi
     * çerezi aranıyor ve o çerez adresteki oturumu göstermeli.
     */
    #[Test]
    public function vekil_cerezi_olmadan_sayfa_acilmaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);

        $this->actingAs($user)->get($oturum['adres'])->assertForbidden();
    }

    /**
     * BAŞKA KİŞİNİN ÇEREZİ BU BAĞLANTIYI AÇMAZ.
     *
     * Kural bir dönem "adres ile çerez AYNI oturum olmalı" idi. Her arayüz
     * kendi portunda açılmaya başlayınca aynı tarayıcıda birden çok oturum
     * yaşıyor ve çerez yalnızca EN SON açılanı gösteriyor: kişinin kendi
     * ikinci oturumu bile reddediliyordu (iLO konsolunda
     * "403 /cihaz-oturum/<jeton>/acilis.js").
     *
     * Korunan şey aynı kaldı: bağlantıyı ele geçiren BAŞKASI onu kendi
     * çereziyle açamaz.
     */
    #[Test]
    public function baska_kisinin_cerezi_acmaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $baskasi = User::factory()->admin()->create();

        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);
        $onunki = app(ArayuzOturumu::class)->ac($credential, (int) $baskasi->id);

        $this->withCookies([ArayuzOturumu::COOKIE => $onunki['token']])
            ->get($oturum['adres'])
            ->assertForbidden();

        /* Kişinin KENDİ ikinci oturumu ise engel değildir. */
        $this->flushHeaders();
        $kendi = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);

        $this->withCookies([ArayuzOturumu::COOKIE => $kendi['token']])
            ->get($oturum['adres'].'acilis.js')
            ->assertOk();
    }

    /**
     * Gerekçe YALNIZCA "kritik" işaretli kayıtta zorunlu (bkz. GerekceKurali).
     *
     * İkisi birlikte sınanıyor: işaretli kayıt gerekçesiz açılmamalı, işaretsiz
     * kayıt gerekçe yüzünden reddedilmemeli. Tek yönü sınamak, kuralın bir gün
     * "hiç sorma"ya ya da "her zaman sor"a kaymasını fark ettirmezdi.
     */
    #[Test]
    public function gerekce_yalnizca_kritik_kayitta_zorunlu(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);

        $kritik = $this->kayit(['credential_group_id' => $group->getKey(), 'is_critical' => true]);

        $this->actingAs($user)
            ->post(route('credentials.arayuz', $kritik), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $sade = $this->kayit(['credential_group_id' => $group->getKey(), 'is_critical' => false]);

        $this->actingAs($user)
            ->post(route('credentials.arayuz', $sade), ['reason' => ''])
            ->assertSessionDoesntHaveErrors('reason');
    }

    #[Test]
    public function yetkisi_olmayan_acamaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.host']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $this->actingAs($user)
            ->post(route('credentials.arayuz', $credential), ['reason' => 'bakim'])
            ->assertForbidden();
    }

    #[Test]
    public function acilis_sayfasi_parolayi_tasimaz_ve_taban_adresi_yazar(): void
    {
        Http::fake(['https://10.0.0.44/ui/' => Http::response('<html><head></head><body>ESXi</body></html>')]);

        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        [$oturum, $cerez] = $this->devralarakAc($user, $credential);

        $yanit = $this->withCookies($cerez)->get($oturum['adres']);

        $yanit->assertOk();
        $yanit->assertSee('<base href="/cihaz-oturum/'.$oturum['token'].'/">', false);

        /*
         * Betik SATIR İÇİ DEĞİL, ayrı dosya: cihazların Güvenlik İlkesi satır
         * içi betiği reddediyor (VAMI'de ölçüldü), aynı kökenden geleni
         * kabul ediyor.
         */
        $yanit->assertSee('<script src="/cihaz-oturum/'.$oturum['token'].'/acilis.js"></script>', false);

        /* Parola sayfanın GÖVDESİNDE DEĞİL; betik onu ayrı uçtan ister. */
        $this->assertStringNotContainsString('ESXI-PAROLASI', $yanit->getContent());

        $betik = $this->withCookies($cerez)->get($oturum['adres'].'acilis.js');
        $betik->assertOk();
        $this->assertStringContainsString('javascript', (string) $betik->headers->get('Content-Type'));
        $this->assertStringNotContainsString('ESXI-PAROLASI', $betik->getContent());
        $betik->assertSee('/kimlik', false);
    }

    #[Test]
    public function kimlik_ucu_tek_kullanimliktir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        [$oturum, $cerez] = $this->devralarakAc($user, $credential);
        $adres = $oturum['adres'].'kimlik';

        $this->withCookies($cerez)->get($adres)
            ->assertOk()
            ->assertJson(['kullanici' => 'root', 'parola' => 'ESXI-PAROLASI']);

        /* İkinci okuma yok: sayfa yenilenirse parola bir daha verilmez. */
        $this->withCookies($cerez)->get($adres)->assertStatus(410);
    }

    #[Test]
    public function baskasinin_oturumu_okunamaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);

        /* Çerezi olmayan hiç kimse açamaz — panele girmiş olsa bile. */
        $baskasi = User::factory()->create();

        $this->actingAs($baskasi)->get($oturum['adres'].'kimlik')->assertForbidden();
    }

    /**
     * Bekçi hedefi KENDİ KAYDIMIZDAN söyler. Host Client istediği konağı
     * `x-vsphere-proxy` başlığında yolluyor; ona güvenilseydi panel, istenen
     * her adrese bağlanan açık bir vekile dönerdi.
     */
    #[Test]
    public function bekci_hedefi_istekten_degil_kayittan_alir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $oturum = app(ArayuzOturumu::class)->ac($credential, (int) $user->id);
        $cerez = [ArayuzOturumu::COOKIE => $oturum['token']];

        $this->withCookies($cerez)
            ->withHeader('X-Vsphere-Proxy', 'https://10.0.0.44:443/sdk')
            ->get(route('ajax.arayuz.guard'))
            ->assertNoContent()
            ->assertHeader('X-Eys-Hedef', '10.0.0.44:443');

        /* Kayıtta olmayan bir konak istenirse bağlanılmaz. */
        $this->withCookies($cerez)
            ->withHeader('X-Vsphere-Proxy', 'https://169.254.169.254:443/sdk')
            ->get(route('ajax.arayuz.guard'))
            ->assertForbidden();
    }

    #[Test]
    public function bekci_cerezsiz_istege_403_doner(): void
    {
        /* nginx auth_request yalnızca 2xx/401/403 anlar; 302 olsaydı 500 olurdu. */
        $this->get(route('ajax.arayuz.guard'))->assertForbidden();
    }

    #[Test]
    public function giris_cikis_ve_islem_kayda_gecer(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $oturumlar = app(ArayuzOturumu::class);
        $izleyici = app(ArayuzIzleyici::class);
        $oturum = $oturumlar->bul(($oturumlar->ac($credential, (int) $user->id))['token']);

        $izleyici->kaydet($oturum, $this->zarf('Login'));
        $izleyici->kaydet($oturum, $this->zarf('PowerOnVM_Task'));
        $izleyici->kaydet($oturum, $this->zarf('Logout'));

        $ozetler = ActivityLog::query()->pluck('summary')->implode(' | ');

        $this->assertStringContainsString('ESXi arayüzüne giriş yapıldı', $ozetler);
        $this->assertStringContainsString('işlem: PowerOnVM_Task', $ozetler);
        $this->assertStringContainsString('ESXi arayüzünden çıkış yapıldı', $ozetler);

        $this->assertDatabaseHas(KonsolOturumu::class, [
            'token' => $oturum['token'],
            'closed_reason' => 'cikis',
        ]);
    }

    /**
     * Okuma çağrıları kayda girmez, bilinmeyen çağrılar GİRER.
     *
     * Süzgeç "neyi atlayacağız" diye kuruludur: beyaz liste, yeni bir yönetim
     * çağrısını sessizce kaçırmak demek olurdu.
     */
    #[Test]
    public function okuma_cagrilari_kayda_girmez(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $oturumlar = app(ArayuzOturumu::class);
        $izleyici = app(ArayuzIzleyici::class);
        $oturum = $oturumlar->bul(($oturumlar->ac($credential, (int) $user->id))['token']);

        foreach (['QueryDateTime', 'HostImageConfigGetProfile', 'RetrieveProperties',
            'WaitForUpdatesEx', 'installDate', 'SetLocale'] as $gurultu) {
            $izleyici->kaydet($oturum, $this->zarf($gurultu));
        }

        $this->assertSame(0, ActivityLog::query()->where('action', ActivityAction::DeviceOperation->value)->count());

        /* Tanımadığımız bir çağrı yazılır: yeni yönetim yöntemleri kaçmasın. */
        $izleyici->kaydet($oturum, $this->zarf('VMwareYeniBirSeyYapti'));

        $this->assertSame(1, ActivityLog::query()->where('action', ActivityAction::DeviceOperation->value)->count());
    }

    private function zarf(string $yontem): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soapenv:Body><'.$yontem.' xmlns="urn:vim25"><_this type="HostSystem">ha-host</_this>'
            .'</'.$yontem.'></soapenv:Body></soapenv:Envelope>';
    }

    /**
     * vSPHERE KONSOLU SEKMEDE AÇILIR.
     *
     * vSphere konsolu açarken pencereye boyut veriyor ve tarayıcı boyut gören
     * her çağrıyı AÇILIR PENCERE sayıyor: ayrı pencere, adres çubuğu yok,
     * sekme listesinde görünmüyor. Enjekte edilen betik boyutu düşürüyor,
     * adresi ve pencere adını olduğu gibi bırakıyor.
     *
     * Yalnızca vSphere ailesinde: iLO gibi arayüzlerin kendi açılır
     * pencereleri var ve boyutları işlerine yarıyor.
     */
    #[Test]
    public function vsphere_konsolu_sekmede_acilir(): void
    {
        Http::fake(['https://10.0.0.44/ui/' => Http::response('<html><head></head><body>ESXi</body></html>')]);

        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        [$oturum, $cerez] = $this->devralarakAc($user, $credential);

        $betik = $this->withCookies($cerez)->get($oturum['adres'].'acilis.js');

        $betik->assertOk();
        $this->assertStringContainsString('window.open', $betik->getContent());

        /* vSphere dışındaki arayüzlerde sarmalayıcı hiç yazılmaz. */
        $digeri = view('credentials.arayuz.ayrilis', [
            'ayrilisAdresi' => '/cihaz-oturum/ayril',
            'sekmeyeAc' => false,
        ])->render();

        $this->assertStringNotContainsString('window.open', $digeri);
    }

    /**
     * CİHAZ GİRİŞİ TUTMAZSA 500 DEĞİL, ANLAŞILIR BİR SAYFA.
     *
     * Panelde her şey yerindeyken cihaz girişi yine de tutmayabilir: kasadaki
     * parola eskimiştir, hesap kilitlenmiştir, cihaz kapalıdır. Bu uygulamanın
     * NORMAL çalışması; istisna yukarı çıkınca kullanıcı "HTTP 500" görüyordu
     * (örneğin cihaz kasadaki parolayı kabul etmediğinde).
     */
    #[Test]
    public function cihaz_girisi_tutmazsa_anlasilir_sayfa_doner(): void
    {
        config(['remote.arayuz.turler' => ['plesk']]);

        /* Cihaz girişi reddediyor: sunucuda oturum açılamıyor. */
        Http::fake(['*' => Http::response('', 401)]);

        [$user, $group] = $this->yetkili(['view', 'field.host', 'session']);

        $credential = Credential::factory()->create([
            'credential_group_id' => $group->getKey(),
            'access_type' => AccessType::Web,
            'host' => '10.0.0.9',
            'port' => 8443,
            'login_url' => 'https://10.0.0.9:8443/login_up.php',
            'username' => 'admin',
            'password' => 'eski-parola',
        ]);

        $yanit = $this->actingAs($user)
            ->post(route('credentials.arayuz', $credential), ['reason' => 'bakim']);

        $yanit->assertStatus(502);
        $yanit->assertSee('Arayüz açılamadı');
        $yanit->assertSee($credential->username, false);

        /* Parola ekrana ASLA düşmez — hata sayfasında bile. */
        $this->assertStringNotContainsString('eski-parola', $yanit->getContent());

        /* Deneme denetim defterine geçer. */
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityAction::LoginFailed->value,
            'subject_id' => $credential->getKey(),
        ]);
    }
}
