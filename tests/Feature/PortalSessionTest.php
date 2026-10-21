<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\CihazArayuzu\ArayuzTuru;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Portal oturumu.
 *
 * Kilitlenen şey: parola kullanıcıya HİÇ gösterilmiyor, ayrı yetki
 * gerektiriyor, gerekçesiz açılmıyor ve her açış kayda geçiyor.
 */
class PortalSessionTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'access_type' => AccessType::Membership,
            'operating_system' => OperatingSystem::Portal,
            'host' => null,
            'port' => null,
            'url' => 'https://portal.ornek.test',
            'login_url' => 'https://portal.ornek.test/giris',
            'login_username_field' => 'login_user',
            'login_password_field' => 'pass_user',
            'username' => 'portal-uye',
            'password' => 'PORTAL-PAROLASI',
            ...$ustune,
        ]);
    }

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
    public function oturum_ayri_bir_yetki_ister(): void
    {
        /* Parolayı görebiliyor ama oturum açamıyor. */
        [$user, $group] = $this->yetkili(['view', 'field.password']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'uyelik yenileme'])
            ->assertForbidden();
    }

    #[Test]
    public function parolayi_goremeyen_oturum_acamaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'uyelik yenileme'])
            ->assertForbidden();
    }

    /** Gerekçe YALNIZCA "kritik" işaretli kayıtta zorunlu (bkz. GerekceKurali). */
    #[Test]
    public function gerekce_kritik_kayitta_zorunlu(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey(), 'is_critical' => true]);

        $this->actingAs($user)
            ->post(route('credentials.session', $credential))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, ActivityLog::where('action', 'portal_session')->count());

        /* İşaretsiz kayıt gerekçe yüzünden reddedilmez. */
        $sade = $this->kayit(['credential_group_id' => $group->getKey(), 'is_critical' => false]);

        $this->actingAs($user)
            ->post(route('credentials.session', $sade))
            ->assertSessionDoesntHaveErrors('reason');
    }

    #[Test]
    public function firlatma_sayfasi_parolayi_gizli_alanda_hedefe_gonderir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $response = $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'uyelik yenileme']);

        $response->assertOk();

        /* Form hedefe POST ediyor ve alan adları kayıttan geliyor. */
        $response->assertSee('action="https://portal.ornek.test/giris"', false);
        $response->assertSee('name="login_user"', false);
        $response->assertSee('name="pass_user"', false);

        /* Önbelleğe alınmamalı ve hedefe kaynak sızmamalı. */
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    #[Test]
    public function parola_panelin_hicbir_ekraninda_gorunmez(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        /*
         * Fırlatma sayfası dışında parola HİÇBİR yerde geçmemeli: ne listede,
         * ne detayda, ne düzenleme formunda.
         */
        foreach ([
            route('credentials.index', ['kayit' => $credential->uuid]),
            route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle']),
        ] as $url) {
            $this->actingAs(User::factory()->admin()->create())
                ->get($url)
                ->assertDontSee('PORTAL-PAROLASI');
        }
    }

    #[Test]
    public function her_acis_gerekcesiyle_kayda_gecer(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'uyelik odemesi'])
            ->assertOk();

        $log = ActivityLog::where('action', 'portal_session')->latest('id')->first();

        $this->assertNotNull($log, 'Portal oturumu kayda geçmeli.');
        $this->assertSame('uyelik odemesi', $log->reason);
        $this->assertSame($user->id, $log->user_id);
        $this->assertStringNotContainsString('PORTAL-PAROLASI', $log->summary);
    }

    #[Test]
    public function giris_formu_tanimsizsa_ac_denemez(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'session']);

        $credential = $this->kayit([
            'credential_group_id' => $group->getKey(),
            'login_url' => null,
        ]);

        $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'deneme'])
            ->assertNotFound();
    }

    #[Test]
    public function get_ile_acilamaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'session']);
        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        /* Adres çubuğuna yazılabilseydi tarayıcı geçmişine ve erişim kaydına düşerdi. */
        $this->actingAs($user)
            ->get('/erisim/'.$credential->uuid.'/oturum')
            ->assertMethodNotAllowed();
    }

    /**
     * Form girişi olmayan arayüzde yanlış yol tarif edilmez.
     *
     * ESXi'nin arayüzü kimliği kendi betiğiyle API'ye gönderiyor; ortada
     * doldurulacak bir form yok. "Düzenle ekranından giriş adresini girin"
     * demek, olmayan bir şeye yönlendirmekti. Yapılabilecek en dürüst şey
     * arayüzü açmak; parola zaten tek tıkla panoya kopyalanıyor.
     */
    /**
     * ESXi tek tıkla AÇILMAZ — ve açılıyormuş gibi düğme gösterilmez.
     *
     * Oturumu SOAP `Login` açıyor ve çerez formla da yazdırılabiliyor; ama
     * Host Client her çağrıya kendi ürettiği `VMware-CSRF-Token` başlığını
     * koyuyor, ESXi de oturumu GİRİŞTEKİ jetona bağlıyor. Form isteğe başlık
     * ekleyemediği için jetonlar hiçbir zaman tutmaz (ölçüm ve ayrıntı için
     * bkz. PortalLogin'deki "ESXİ NEDEN YOK" notu).
     *
     * Bu sınama o kararı kilitler: biri yeniden denerse burada düşer.
     */
    #[Test]
    public function esxi_icin_tek_tikla_oturum_dugmesi_gosterilmez(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);

        $credential = $this->kayit([
            'credential_group_id' => $group->getKey(),
            'access_type' => AccessType::Esxi,
            'operating_system' => OperatingSystem::Esxi,
            'host' => '10.0.0.44',
            'url' => null,
            'login_url' => null,
            'username' => 'root',
            'password' => 'ESXI-PAROLASI',
        ]);

        $this->assertFalse($credential->can_open_portal_session);

        /* Uç de kapalı: ekranı atlayıp doğrudan çağırmak da işe yaramaz. */
        $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'bakim'])
            ->assertNotFound();

        /*
         * Oturum yine de açılıyor — ama form gönderimiyle değil, arayüzün
         * panelin kökeninden VEKİLLENMESİYLE (bkz. ArayuzOturumTest).
         */
        $this->assertSame(ArayuzTuru::Esxi, $credential->arayuz_turu);
    }

    /**
     * vCenter: giriş vSphere Client'a değil, vCenter'ın SAML kimlik
     * sağlayıcısına gider. Adres her fırlatmada cihazdan alınır.
     */
    #[Test]
    public function vcenter_oturumu_kimlik_saglayiciya_gonderilir(): void
    {
        Http::fake([
            'https://10.0.0.55/ui/login' => Http::response('', 302, [
                'Location' => 'https://10.0.0.55/websso/SAML2/SSO/vsphere.local?SAMLRequest=ABC',
            ]),
        ]);

        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);

        $credential = $this->kayit([
            'credential_group_id' => $group->getKey(),
            'access_type' => AccessType::Vcenter,
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '10.0.0.55',
            'url' => null,
            'login_url' => null,
            'username' => 'administrator@vsphere.local',
            'password' => 'VC-PAROLASI',
        ]);

        $response = $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'bakim']);

        $response->assertOk();
        $response->assertSee('action="https://10.0.0.55/websso/SAML2/SSO/vsphere.local?SAMLRequest=ABC"', false);
        $response->assertSee('name="CastleAuthorization"', false);
        $response->assertSee('value="Basic '.base64_encode('administrator@vsphere.local:VC-PAROLASI').'"', false);

        /* Parola yalnızca base64'lü alanda; ham hâli sayfada yok. */
        $this->assertStringNotContainsString('VC-PAROLASI', $response->getContent());
    }

    /**
     * Yönlendirme BAŞKA bir makineye gidiyorsa kimlik gönderilmez.
     *
     * Adres cihazın yanıtından geliyor; ele geçirilmiş bir cihaz parolayı tek
     * adımda üçüncü bir yere taşıyabilirdi.
     */
    #[Test]
    public function vcenter_baska_makineye_yonlendirmeyi_reddeder(): void
    {
        Http::fake([
            'https://10.0.0.55/ui/login' => Http::response('', 302, [
                'Location' => 'https://saldirgan.ornek.test/websso?SAMLRequest=ABC',
            ]),
        ]);

        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);

        $credential = $this->kayit([
            'credential_group_id' => $group->getKey(),
            'access_type' => AccessType::Vcenter,
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '10.0.0.55',
            'url' => null,
            'login_url' => null,
            'username' => 'administrator@vsphere.local',
            'password' => 'VC-PAROLASI',
        ]);

        $this->actingAs($user)
            ->post(route('credentials.session', $credential), ['reason' => 'bakim'])
            ->assertStatus(503);

        $this->assertDatabaseCount(ActivityLog::class, 0);
    }

    /**
     * Liste çizilirken cihaza ÇIKILMAZ.
     *
     * vCenter'ın kimlik sağlayıcı adresi ağdan geliyor; listede her kayıt için
     * sorulsaydı bir cihazın kapalı olması bütün ekranı bekletirdi.
     */
    #[Test]
    public function liste_cizilirken_cihaza_istek_gitmez(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);

        $this->kayit([
            'credential_group_id' => $group->getKey(),
            'access_type' => AccessType::Vcenter,
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '10.0.0.55',
            'url' => null,
            'login_url' => null,
        ]);

        $esxi = $this->kayit([
            'credential_group_id' => $group->getKey(),
            'access_type' => AccessType::Esxi,
            'operating_system' => OperatingSystem::Esxi,
            'host' => '10.0.0.44',
            'url' => null,
            'login_url' => null,
        ]);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $esxi->uuid]))
            ->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function form_girisi_olmayan_arayuz_icin_acma_baglantisi_verilir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'field.host', 'session']);

        $credential = $this->kayit([
            'credential_group_id' => $group->getKey(),
            'operating_system' => OperatingSystem::Esxi,
            'host' => '10.0.0.44',
            'url' => null,
            'login_url' => null,
        ]);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid]))
            ->assertOk()
            ->assertSee('https://10.0.0.44/ui/', false)
            ->assertSee('Arayüzü aç')
            ->assertDontSee('Düzenle ekranından giriş adresini');
    }
}
