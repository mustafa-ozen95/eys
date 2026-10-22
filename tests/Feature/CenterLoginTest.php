<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Kimlik Yönetim Merkezi ile giriş.
 *
 * Bu akışta kasa, kimliği KENDİ sormuyor: merkezin sözüne güveniyor. O
 * yüzden sınanan şey "giriş oluyor mu" değil, güvenin nerede DURDUĞU —
 * merkezden gelen her söz kabul edilmemeli, eşleşmeyen kimlikle hesap
 * açılmamalı ve akışı başlatmadığımız bir dönüş işlenmemeli.
 *
 * Burada duran şey diğer bütün sistemlerin parolaları; yanlış eşleşen bir
 * kimlik, kasanın tamamını açar.
 */
final class CenterLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.test',
            'identity.client_id' => 'istemci-1',
            'identity.client_secret' => 'sir-1',
            'identity.redirect_uri' => 'https://kasa.test/login/center/callback',
            'identity.name' => 'Kimlik Yönetim Merkezi',
        ]);
    }

    /** Merkezin iki ucunu da taklit eder. */
    private function merkez(array $kimlik): void
    {
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton-1']),
            'merkez.test/oauth/userinfo' => Http::response($kimlik),
        ]);
    }

    /** @return array{sub: string, name: string, email: string, email_verified: bool} */
    private function kimlik(array $ustune = []): array
    {
        return ['sub' => 'merkez-1', 'name' => 'Ali Demir', 'email' => 'ali@example.test', 'email_verified' => true, ...$ustune];
    }

    /** Yerleşik rol; testte migration'dan gelmiyorsa açılır. */
    private function rol(string $slug, bool $admin = false, bool $varsayilan = false, array $sablon = []): Role
    {
        return Role::query()->updateOrCreate(
            ['slug' => $slug],
            ['label' => ucfirst($slug), 'is_admin' => $admin, 'is_default' => $varsayilan, 'template' => $sablon],
        );
    }

    /** Akışı, oturumda durum ve doğrulayıcı varmış gibi kurar. */
    private function donus(array $ustune = []): TestResponse
    {
        return $this->withSession(['kimlik_durum' => 'durum-1', 'kimlik_dogrulayici' => 'dogrulayici-1'])
            ->get(route('identity.callback', ['code' => 'kod-1', 'state' => 'durum-1', ...$ustune]));
    }

    /**
     * Merkez açıkken parola formu KALDIRILMAZ, katlanır.
     *
     * Kaldırılsaydı merkez erişilemez olduğu gün kasaya kimse giremezdi —
     * yöneticisi dahil.
     */
    public function test_merkez_acikken_parola_formu_katlanir_ama_durur(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Kimlik Yönetim Merkezi ile giriş')
            ->assertSee('Parolayla giriş')
            ->assertSee('Oturum aç')
            ->assertSee('name="password"', false);
    }

    /** Merkez kapalıyken ekran eskisi gibi: form açık, düğme yok. */
    public function test_merkez_kapaliyken_form_acik_gelir(): void
    {
        config(['identity.acik' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Kimlik Yönetim Merkezi ile giriş')
            ->assertSee('Oturum aç');
    }

    /**
     * Yapılandırılmamış kurulumda ayar AÇIK OLSA DA özellik yok.
     *
     * Ayarın yeteneği geçersiz kılabilmesi, kullanıcıyı çalışmayan bir yola
     * göndermek demekti.
     */
    public function test_yapilandirilmamis_kurulumda_ayar_acik_olsa_da_uc_yoktur(): void
    {
        config(['identity.acik' => true, 'identity.configured' => false]);

        $this->get(route('login'))->assertOk()->assertDontSee('Kimlik Yönetim Merkezi ile giriş');
        $this->get(route('identity.login'))->assertNotFound();
        $this->get(route('identity.callback'))->assertNotFound();
    }

    /** Ayar kapatıldığında uçlar da kapanır: düğmesiz ama açık bir kapı kalmaz. */
    public function test_ayar_kapaliyken_uclar_da_kapanir(): void
    {
        config(['identity.acik' => false]);

        $this->get(route('identity.login'))->assertNotFound();
        $this->get(route('identity.callback'))->assertNotFound();
    }

    public function test_baslangic_merkeze_pkce_ile_yonlendirir(): void
    {
        $yanit = $this->get(route('identity.login'))->assertRedirect();

        $adres = $yanit->headers->get('Location');
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);

        $this->assertStringStartsWith('https://merkez.test/oauth/authorize', $adres);
        $this->assertSame('code', $sorgu['response_type']);
        $this->assertSame('istemci-1', $sorgu['client_id']);
        $this->assertSame('https://kasa.test/login/center/callback', $sorgu['redirect_uri']);

        /*
         * S256 ŞART: "plain" kipinde doğrulayıcı adres çubuğunda açıkça
         * gider ve kodu kapan biri jetona çevirebilirdi.
         */
        $this->assertSame('S256', $sorgu['code_challenge_method']);

        $dogrulayici = session('kimlik_dogrulayici');
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $dogrulayici, true)), '+/', '-_'), '='),
            $sorgu['code_challenge'],
            'Meydan okuma, oturumda duran doğrulayıcının özeti olmalı.',
        );

        $this->assertSame(session('kimlik_durum'), $sorgu['state']);
    }

    /**
     * Başlatmadığımız bir dönüş İŞLENMEZ.
     *
     * Saldırgan kendi kodunu kurbanın tarayıcısında kullandırıp onu kendi
     * merkez hesabına bağlayabilirdi.
     */
    public function test_durum_uyusmazsa_giris_yapilmaz(): void
    {
        Http::fake();
        $kullanici = User::factory()->create(['email' => 'ali@example.test']);
        UserIdentity::create(['user_id' => $kullanici->id, 'provider' => 'kimlik', 'subject' => 'merkez-1']);

        $this->donus(['state' => 'baska-durum'])->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_oturumda_durum_yoksa_giris_yapilmaz(): void
    {
        Http::fake();

        $this->get(route('identity.callback', ['code' => 'kod-1', 'state' => 'durum-1']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_bagli_kimlik_dogrudan_giris_yapar(): void
    {
        $this->merkez($this->kimlik(['email' => 'baska@example.test']));

        $kullanici = User::factory()->create(['email' => 'ali@example.test']);
        UserIdentity::create(['user_id' => $kullanici->id, 'provider' => 'kimlik', 'subject' => 'merkez-1']);

        $this->donus()->assertRedirect(route('credentials.index'));

        /* Bağ SUBJECT ile kuruluydu: merkezdeki e-posta değişmiş olsa da giriş bozulmuyor. */
        $this->assertAuthenticatedAs($kullanici);
    }

    public function test_dogrulanmis_eposta_tek_hesapla_eslesir_ve_bag_kurulur(): void
    {
        $this->merkez($this->kimlik());

        $kullanici = User::factory()->create(['email' => 'ali@example.test']);

        $this->donus()->assertRedirect(route('credentials.index'));

        $this->assertAuthenticatedAs($kullanici);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $kullanici->id,
            'provider' => 'kimlik',
            'subject' => 'merkez-1',
        ]);
    }

    /**
     * Doğrulanmamış adresle eşleşme YOK.
     *
     * Merkezde meslektaşının adresiyle hesap açan biri, onun yerine bu panele
     * girebilirdi. (Merkezde adresi doğrulayan şey posta değil, hesabı
     * etkinleştiren yöneticinin onayıdır.)
     */
    public function test_dogrulanmamis_eposta_eslesmez(): void
    {
        $this->merkez($this->kimlik(['email_verified' => false]));

        User::factory()->create(['email' => 'ali@example.test']);

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('user_identities', 0);
    }

    /**
     * Aynı adresi iki hesap taşıyamaz.
     *
     * E-postayla eşleşmenin tek karşılık şartı, ancak adres BENZERSİZSE
     * anlamlı: iki hesap aynı adresi taşısaydı hangisine girileceği belli
     * olmazdı. Kural veritabanında duruyor; `AccountMatcher` ayrıca
     * `limit(2)` ile sayıyor, yani kısıt bir gün kalkarsa eşleşme tahmine
     * dönüşmez, sessizce durur.
     */
    public function test_eposta_benzersizdir(): void
    {
        User::factory()->create(['email' => 'ali@example.test']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->create(['email' => 'ali@example.test']);
    }

    /**
     * MERKEZ KAPIYI KAPATTIYSA KAPI KAPALIDIR.
     *
     * Hangi rolün buraya girebileceğine merkez karar veriyor ve rolü izinli
     * olmayana `/oauth/userinfo` ucunda 403 dönüyor. Panel bunu "merkez
     * cevap vermedi"den AYIRMALI: orada kullanıcı parolasıyla girişe düşer,
     * burada kapı kapalıdır ve hesabı varsa bile merkezden giremez.
     */
    public function test_merkez_rolu_reddederse_giris_olmaz(): void
    {
        User::factory()->create(['email' => 'ali@example.test']);

        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton-1']),
            'merkez.test/oauth/userinfo' => Http::response(
                ['error' => 'Bu uygulamaya erişim yetkiniz yok.', 'code' => 'role_forbidden'],
                403,
            ),
        ]);

        $this->donus()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Bu uygulamaya erişim yetkiniz yok.']);

        $this->assertGuest();
    }

    /**
     * MERKEZ İZİN VERMEDİYSE HESAP AÇILMAZ.
     *
     * Kararı merkez veriyor ve yanıtında söylüyor (`can_create_account`).
     * Söylemiyorsa yanıt HAYIR sayılır: eksik bilgi yetki üretmemeli.
     */
    public function test_merkez_izin_vermediyse_hesap_acilmaz(): void
    {
        $this->merkez($this->kimlik(['email' => 'yabanci@example.test']));

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'yabanci@example.test']);
    }

    /**
     * Merkez izin verdiğinde hesap MERKEZDEKİ ROLÜN yerel karşılığıyla açılır.
     *
     * Roller kardeş panellerde bilerek aynı adlarla duruyor; eşleme slug iledir.
     */
    public function test_merkez_izin_verince_hesap_merkezdeki_rolle_acilir(): void
    {
        $this->rol('network');

        $this->merkez($this->kimlik([
            'email' => 'deniz@example.test',
            'name' => 'Deniz Kaya',
            'role' => 'network',
            'can_create_account' => true,
        ]));

        $this->donus()->assertRedirect();

        $yeni = User::where('email', 'deniz@example.test')->firstOrFail();

        $this->assertAuthenticatedAs($yeni);
        $this->assertSame('Deniz Kaya', $yeni->name);
        $this->assertSame('network', $yeni->role?->slug);
        $this->assertTrue($yeni->isActive());
    }

    /**
     * MERKEZİN YÖNETİCİSİ BURADA KENDİLİĞİNDEN YÖNETİCİ OLMAZ.
     *
     * Merkezin yöneticisi kimlikleri yönetir; buranın yöneticisi bütün
     * sistemlerin parolalarını görür. Devrolsaydı merkezde rol dağıtabilen
     * biri, buradaki hiçbir yöneticinin haberi olmadan kasanın tamamını
     * açardı.
     */
    public function test_merkezdeki_yoneticilik_kendiliginden_devrolmaz(): void
    {
        $this->rol('admin', admin: true);
        $this->rol('teknik', varsayilan: true);

        $this->merkez($this->kimlik([
            'email' => 'yonetici@example.test',
            'role' => 'admin',
            'can_create_account' => true,
        ]));

        $this->donus()->assertRedirect();

        $yeni = User::where('email', 'yonetici@example.test')->firstOrFail();

        $this->assertSame('teknik', $yeni->role?->slug);
        $this->assertFalse($yeni->isAdmin());
    }

    /** Açıkça devredilirse geçer; karar yöneticinin. */
    public function test_yonetici_devri_acikken_yoneticilik_gecer(): void
    {
        config(['identity.yonetici_devri' => true]);

        $this->rol('admin', admin: true);

        $this->merkez($this->kimlik(['email' => 'yonetici@example.test', 'role' => 'admin', 'can_create_account' => true]));

        $this->donus()->assertRedirect();

        $this->assertTrue(User::where('email', 'yonetici@example.test')->firstOrFail()->isAdmin());
    }

    /** Tanınmayan rol adı yetki üretmez: en dar role düşer. */
    public function test_taninmayan_rol_varsayilana_duser(): void
    {

        $this->rol('teknik', varsayilan: true);

        $this->merkez($this->kimlik(['email' => 'yabanci@example.test', 'role' => 'boyle-bir-rol-yok', 'can_create_account' => true]));

        $this->donus()->assertRedirect();

        $this->assertSame('teknik', User::where('email', 'yabanci@example.test')->firstOrFail()->role?->slug);
    }

    /**
     * MERKEZDEKİ DÖRT ROLÜN BURADA DA KARŞILIĞI VAR.
     *
     * Muhasebe rolü burada yokken merkezden gelen muhasebeci varsayılana —
     * yani teknik rolüne — düşüyordu: muhasebeciye teknik yetkisi vermek
     * olurdu.
     */
    public function test_merkezdeki_dort_rolun_karsiligi_vardir(): void
    {
        foreach (['admin', 'network', 'teknik', 'muhasebe'] as $slug) {
            $this->assertNotNull(
                Role::query()->where('slug', $slug)->first(),
                "Merkezdeki '{$slug}' rolünün burada karşılığı olmalı.",
            );
        }
    }

    /**
     * DOĞRULANMAMIŞ E-POSTAYLA HESAP AÇILMAZ.
     *
     * Açılsaydı, meslektaşının adresiyle merkezde hesap açan biri bu kasanın
     * kapısını da açmış olurdu.
     */
    public function test_dogrulanmamis_eposta_ile_hesap_acilmaz(): void
    {

        $this->merkez($this->kimlik(['email' => 'sahte@example.test', 'email_verified' => false, 'can_create_account' => true]));

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'sahte@example.test']);
    }

    /** Açılan hesap PAROLA İLE girilemez: kapısı merkezdir. */
    public function test_acilan_hesabin_parolasi_bilinmez(): void
    {

        $this->rol('teknik', varsayilan: true);

        $this->merkez($this->kimlik(['email' => 'deniz@example.test', 'role' => 'teknik', 'can_create_account' => true]));
        $this->donus();

        $yeni = User::where('email', 'deniz@example.test')->firstOrFail();

        foreach (['', 'password', 'deniz@example.test', 'teknik'] as $deneme) {
            $this->assertFalse(Hash::check($deneme, $yeni->password));
        }
    }

    public function test_pasif_hesap_merkezden_de_giremez(): void
    {
        $this->merkez($this->kimlik());

        User::factory()->create(['email' => 'ali@example.test', 'status' => UserStatus::Passive]);

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** Merkez cevap vermiyorsa kullanıcı ekranda kalmaz, parola yoluna döner. */
    public function test_merkez_ulasilamazsa_giris_ekranina_donulur(): void
    {
        Http::fake(['merkez.test/*' => Http::response('', 500)]);

        User::factory()->create(['email' => 'ali@example.test']);

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Sertifika doğrulaması HİÇBİR DALDA kapatılmaz.
     *
     * Merkez şirket içi bir adreste durabilir ve orada genel bir sertifika
     * otoritesi yoktur; akla gelen ilk çözüm `verify => false`'tur ve
     * yanlıştır — jeton takasında araya giren biri istemci sırrını okur.
     * Doğru olan, güvenilen kökü DARALTMAK: doğrulama açık kalır, yalnızca
     * kime güvenileceği daralır.
     */
    public function test_kok_verilince_dogrulama_daraltilir_kapatilmaz(): void
    {
        $secenekler = [];

        config(['identity.ca_cert' => '/tmp/merkez.crt']);

        Http::fake(function ($istek, $ayar) use (&$secenekler) {
            $secenekler[] = $ayar;

            return str_contains($istek->url(), '/oauth/token')
                ? Http::response(['access_token' => 'jeton-1'])
                : Http::response($this->kimlik());
        });

        User::factory()->create(['email' => 'ali@example.test']);

        $this->donus()->assertRedirect(route('credentials.index'));

        $this->assertCount(2, $secenekler, 'Hem jeton hem kullanıcı isteği gitmeli.');

        foreach ($secenekler as $ayar) {
            $this->assertSame('/tmp/merkez.crt', $ayar['verify']);
        }
    }

    /** Kök verilmemişse olağan doğrulama sürer; kapatma diye bir dal yok. */
    public function test_kok_yoksa_dogrulama_olagan_kalir(): void
    {
        $secenekler = [];

        config(['identity.ca_cert' => null]);

        Http::fake(function ($istek, $ayar) use (&$secenekler) {
            $secenekler[] = $ayar;

            return str_contains($istek->url(), '/oauth/token')
                ? Http::response(['access_token' => 'jeton-1'])
                : Http::response($this->kimlik());
        });

        User::factory()->create(['email' => 'ali@example.test']);

        $this->donus()->assertRedirect(route('credentials.index'));

        foreach ($secenekler as $ayar) {
            $this->assertNotFalse($ayar['verify'] ?? true, 'Doğrulama kapatılmış.');
        }
    }

    /**
     * Kod TEK KULLANIMLIK: doğrulayıcı ve durum oturumdan silinir.
     *
     * Kalsalardı geri düğmesiyle aynı dönüş yeniden işlenebilirdi.
     */
    public function test_akis_bilgileri_oturumdan_silinir(): void
    {
        $this->merkez($this->kimlik());

        User::factory()->create(['email' => 'ali@example.test']);

        $this->donus();

        $this->assertNull(session('kimlik_durum'));
        $this->assertNull(session('kimlik_dogrulayici'));
    }

    /**
     * MERKEZDE ROLÜ YOKSA HESAP AÇILMAZ.
     *
     * Merkezde yeni açılmış, henüz kimsenin bakmadığı hesap rolsüz duruyor.
     * Rolsüzü de içeri alsaydık, merkezde kendi kendine kaydolan herkes buraya
     * varsayılan rolle düşerdi — oysa rol atamak, o kişiye bakılmış olmasının
     * kendisidir.
     */
    public function test_merkezde_rolu_olmayan_icin_hesap_acilmaz(): void
    {

        $this->rol('teknik', varsayilan: true);

        $this->merkez($this->kimlik(['email' => 'rolsuz@example.test', 'role' => null, 'can_create_account' => true]));

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'rolsuz@example.test']);
    }

    /**
     * AÇILAN HESAP ROLÜN YETKİLERİYLE ÇALIŞIR.
     *
     * Yetki kopyalanmaz: rol canlı okunuyor. Hesabın tek bir yetki satırı
     * olmadan rolünün verdiğini görmesi gerekir.
     */
    public function test_acilan_hesap_rolun_yetkileriyle_calisir(): void
    {

        $grup = CredentialGroup::factory()->create(['name' => 'srv-a']);

        $this->rol('network', sablon: [
            'credential_group' => ['all' => ['view'], 'groups' => []],
        ]);

        $this->merkez($this->kimlik(['email' => 'deniz@example.test', 'role' => 'network', 'can_create_account' => true]));

        $this->donus();

        $yeni = User::where('email', 'deniz@example.test')->firstOrFail();

        $this->assertSame(0, $yeni->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue($yeni->can('viewAny', Credential::class), 'Rolün yetkisi geçerli olmalı.');
    }
}
