<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Enums\RotationStage;
use App\Enums\RotationStatus;
use App\Jobs\RepairRotationJob;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\PasswordRotation;
use App\Models\User;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\Drivers\SshPasswordDriver;
use App\Services\Rotation\Drivers\WindowsPasswordDriver;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeLimitedPasswordDriver;
use Tests\Support\FakePasswordDriver;
use Tests\TestCase;

/**
 * Döndürme turunun sırası.
 *
 * Canlı bir makineye BAĞLANILMAZ (bkz. .ai/rules/calisma.md). Buradaki soru
 * "SSH çalışıyor mu" değil: bir şey ters gittiğinde makineye girecek bir
 * yolumuz kalıyor mu, ve açıkta fazladan bir yetki bırakıyor muyuz.
 */
class PasswordRotationTest extends TestCase
{
    use RefreshDatabase;

    private FakePasswordDriver $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = new FakePasswordDriver(['root' => 'eski-parola']);

        config(['rotation.drivers' => ['ssh' => FakePasswordDriver::class]]);
        $this->app->instance(FakePasswordDriver::class, $this->device);
    }

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.9',
            'username' => 'root',
            'password' => 'eski-parola',
            'rotation_enabled' => true,
            ...$ustune,
        ]);
    }

    /**
     * Ayrı bir döndürme hesabı verilmemişse kaydın KENDİ hesabıyla bağlanılır.
     *
     * Kurtarma hesapsız sıra (API anahtarlı servisler, WinRM'li Windows)
     * yalnızca döndürme hesabına bakıyordu; alan boş olan kayıtlarda boş
     * kimlikle bağlanmaya çalışıp ilk adımda düşüyordu. Ekrandaki ipucu ise
     * "boş bırakılırsa kaydın kendi hesabı kullanılır" diyordu.
     */
    /**
     * Onarım da İSTEK İÇİNDE çalışmaz.
     *
     * Uzak makineye bağlanıp sırayla üç kimlik dener; her deneme bağlantı
     * zaman aşımı kadar sürebilir. Döndürmenin kendisi kuyruktayken onarımın
     * tarayıcıyı bekletmesi için bir sebep yok.
     */
    #[Test]
    public function turu_kapatma_islemi_kuyruga_alinir(): void
    {
        Queue::fake();

        $this->device->failNewPasswordVerify = true;

        $credential = $this->kayit();
        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertTrue($rotation->recovery_active, 'Hazırlık: makinede kurtarma hesabı kalmalı.');

        $user = User::factory()->admin()->create();

        /* Yarıda kalmış tur kapatma isteğinden ESKİDİR (gerçekte dakikalar). */
        $this->travel(2)->seconds();

        $this->actingAs($user)
            ->post(route('credentials.rotate.repair', [$credential, $rotation]), ['reason' => 'Turu kapat'])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'sıraya alındı'));

        Queue::assertPushed(RepairRotationJob::class, 1);

        /* İş çalışmadan makineye dokunulmamalı. */
        $this->assertTrue($rotation->fresh()->recovery_active);

        /*
         * Kapatma sıradayken kayıt paneli YOKLAR ve düğme gizlenir: sayfa
         * iş bitmeden döner, düğme kart yenilenene kadar ekranda kalmamalı.
         */
        $this->assertNotNull(RotationLauncher::pending($credential));
        $this->actingAs($user)->get(route('credentials.index', ['kayit' => $credential->uuid]))
            ->assertOk()
            ->assertSee('data-ilerleme-adres', false)
            ->assertDontSee('>Turu kapat</button>', false);

        /* İş bitince iz düşer; yoklama "bitti" der ve sayfa tazelenir. */
        $this->device->failNewPasswordVerify = false;
        (new RepairRotationJob($rotation->fresh(), $user))->handle(app(PasswordRotator::class));

        $this->assertNull(RotationLauncher::pending($credential));
        $this->actingAs($user)->getJson(route('credentials.rotate.progress', $credential))
            ->assertOk()->assertJson(['calisiyor' => false]);
    }

    #[Test]
    public function kurtarma_hesabi_yoksa_onarim_isi_hic_acilmaz(): void
    {
        Queue::fake();

        $credential = $this->kayit();
        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertFalse($rotation->recovery_active, 'Hazırlık: başarılı turda hesap kalmaz.');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('credentials.rotate.repair', [$credential, $rotation]), ['reason' => 'Turu kapat'])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'kurtarma hesabı yok'));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function dondurme_hesabi_bos_birakilirsa_kaydin_kendi_hesabi_kullanilir(): void
    {
        $this->device->supportsRecovery = false;

        $credential = $this->kayit(['rotation_username' => null, 'rotation_password' => null]);

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Succeeded, $rotation->status, $rotation->message ?? '');
        $this->assertSame('root', $this->device->lastIdentity, 'Kaydın kendi hesabıyla bağlanılmalı.');
        $this->assertNotSame('eski-parola', $credential->fresh()->password);
    }

    #[Test]
    public function tur_tamamlaninca_kurtarma_hesabi_makinede_kalmaz(): void
    {
        $credential = $this->kayit();

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Succeeded, $rotation->status);
        $this->assertSame(RotationStage::RecoveryRemoved, $rotation->stage);
        $this->assertFalse($rotation->recovery_active);
        $this->assertFalse($this->device->hasRecoveryAccount(), 'Kurtarma hesabı silinmeli.');

        $yeni = $credential->fresh()->password;
        $this->assertNotSame('eski-parola', $yeni);
        $this->assertSame($yeni, $this->device->accounts['root'], 'Kasa ve makine aynı parolayı taşımalı.');
    }

    #[Test]
    public function calismayan_kurtarma_hesabi_temizlenir_ve_tur_iptal_edilir(): void
    {
        /*
         * Çalışmayan kurtarma hesabı güvence değil, açıkta duran fazladan bir
         * yetkidir. Asıl parola hâlâ geçerli olduğu için temizlenebilir.
         */
        $this->device->recoveryUnusable = true;

        $credential = $this->kayit();

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Failed, $rotation->status);
        $this->assertSame(RotationStage::RecoveryCreated, $rotation->stage);
        $this->assertFalse($this->device->hasRecoveryAccount(), 'Hesap temizlenmeli.');
        $this->assertSame('eski-parola', $this->device->accounts['root'], 'Asıl parolaya dokunulmamalı.');
        $this->assertSame('eski-parola', $credential->fresh()->password);
    }

    #[Test]
    public function parola_kurtarma_hesabiyla_degistirilir_asil_hesapla_degil(): void
    {
        $credential = $this->kayit();

        app(PasswordRotator::class)->rotate($credential);

        /*
         * Değiştirdiğin parolayla bağlı olduğun oturum, iş yarıda kalırsa
         * seni dışarıda bırakır. Bu yüzden en az iki ayrı oturum açılır:
         * biri kurtarma hesabını kurmak, biri parolayı değiştirmek için.
         */
        $this->assertGreaterThanOrEqual(3, $this->device->openCount);
        $this->assertSame($this->device->openCount, $this->device->closedCount, 'Her oturum kapatılmalı.');
    }

    #[Test]
    public function yeni_parola_dogrulanamazsa_kurtarma_hesabi_makinede_birakilir(): void
    {
        $this->device->failNewPasswordVerify = true;

        $credential = $this->kayit();

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Unverified, $rotation->status);
        $this->assertSame(RotationStage::PasswordChanged, $rotation->stage);
        $this->assertTrue($rotation->recovery_active, 'Kapı açık kalmalı.');
        $this->assertTrue($this->device->hasRecoveryAccount());

        /* Kasa geride kalır ama denenen parola kayıtta durur. */
        $this->assertSame('eski-parola', $credential->fresh()->password);
        $this->assertNotNull($rotation->attempted_password);
        $this->assertSame($rotation->attempted_password, $this->device->accounts['root']);
    }

    #[Test]
    public function takilan_tur_onarilinca_kasa_makineye_hizalanir(): void
    {
        $this->device->failNewPasswordVerify = true;

        $credential = $this->kayit();
        $rotation = app(PasswordRotator::class)->rotate($credential);

        /* Arıza geçti: artık yeni parola doğrulanabiliyor. */
        $this->device->failNewPasswordVerify = false;

        $onarilan = app(PasswordRotator::class)->repair($rotation->fresh());

        $this->assertSame(RotationStatus::RolledBack, $onarilan->status);
        $this->assertFalse($onarilan->recovery_active);
        $this->assertFalse($this->device->hasRecoveryAccount(), 'Kurtarma hesabı silinmeli.');
        $this->assertSame(
            $this->device->accounts['root'],
            $credential->fresh()->password,
            'Onarım kasayı makinedeki parolaya hizalamalı.',
        );
    }

    #[Test]
    public function hicbir_kimlik_calismiyorsa_onarim_dogrulanamadi_der(): void
    {
        $this->device->failNewPasswordVerify = true;

        $credential = $this->kayit();
        $rotation = app(PasswordRotator::class)->rotate($credential);

        /* Makine tamamen erişilemez hâle geldi. */
        $this->device->accounts = [];

        $onarilan = app(PasswordRotator::class)->repair($rotation->fresh());

        $this->assertSame(RotationStatus::Unverified, $onarilan->status);
        $this->assertTrue($onarilan->recovery_active, 'Kapı hâlâ açık sayılmalı; elle müdahale gerekiyor.');
    }

    #[Test]
    public function kurtarma_hesabi_adi_her_turda_degisir(): void
    {
        /*
         * Sabit ad, yarıda kalmış eski bir turun hesabıyla çakışır ve tur
         * daha ilk adımda düşerdi.
         */
        $ilk = app(PasswordRotator::class)->rotate($this->kayit());

        $this->device = new FakePasswordDriver(['root' => 'eski-parola']);
        $this->app->instance(FakePasswordDriver::class, $this->device);

        $ikinci = app(PasswordRotator::class)->rotate($this->kayit(['name' => 'ikinci kayit']));

        $this->assertNotSame($ilk->recovery_username, $ikinci->recovery_username);
    }

    /**
     * "OTOMATİK GÜNCELLEMEYE AÇIK" KUTUSU ELLE TURU ENGELLEMEZ.
     *
     * Engelliyordu ve bu saçmaydı: kutunun adı "otomatik" iken, kullanıcının
     * kaydı açıp gerekçesini yazarak başlattığı tur da reddediliyordu; oysa
     * elle tur otomatik değildir.
     *
     * Elle turun kapısı ZATEN AYRI ve yerinde duruyor: `rotate` yetkisi ve
     * ekrandaki onay penceresi. Kutu yalnızca TOPLU turlar içindir — bunu
     * BulkRotationTest sınıyor.
     */
    #[Test]
    public function oto_kutusu_kapaliyken_de_elle_dondurulur(): void
    {
        $credential = $this->kayit(['rotation_enabled' => false]);

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Succeeded, $rotation->status);
        $this->assertGreaterThan(0, $this->device->openCount);
    }

    #[Test]
    public function uretilen_parola_karisabilecek_karakter_icermez(): void
    {
        $credential = $this->kayit();

        app(PasswordRotator::class)->rotate($credential);

        $parola = $credential->fresh()->password;

        $this->assertGreaterThanOrEqual(20, strlen($parola));
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $parola);
    }

    #[Test]
    public function dondurme_ayri_bir_yetki_ister(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();

        /* Parolayı görebiliyor ve düzenleyebiliyor ama döndürme yetkisi yok. */
        foreach (['view', 'field.password', 'update'] as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $this->actingAs($user->fresh())
            ->post(route('credentials.rotate', $credential), ['reason' => 'deneme gerekcesi'])
            ->assertForbidden();

        $this->assertSame(0, PasswordRotation::count());
    }

    #[Test]
    public function parolayi_goremeyen_dondurmez(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();

        foreach (['view', 'rotate'] as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $credential = $this->kayit(['credential_group_id' => $group->getKey()]);

        $this->actingAs($user->fresh())
            ->post(route('credentials.rotate', $credential), ['reason' => 'deneme gerekcesi'])
            ->assertForbidden();
    }

    /** Gerekçe YALNIZCA "kritik" işaretli kayıtta zorunlu (bkz. GerekceKurali). */
    #[Test]
    public function dondurme_kritik_kayitta_gerekce_ister(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('credentials.rotate', $this->kayit(['is_critical' => true])))
            ->assertSessionHasErrors('reason');

        $this->actingAs($user)
            ->post(route('credentials.rotate', $this->kayit(['is_critical' => false])))
            ->assertSessionDoesntHaveErrors('reason');
    }

    #[Test]
    public function surucu_platformdan_secilir_kayit_alani_ezer(): void
    {
        /*
         * Sürücü seçimi kodda değil VERİDE. Bu eşleme sessizce bozulursa
         * yanlış platforma yanlış komut gider; test onu yakalar.
         */
        config(['rotation.drivers' => [
            'ssh' => SshPasswordDriver::class,
            'windows' => WindowsPasswordDriver::class,
        ]]);

        $registry = app(DriverRegistry::class);

        $linux = $this->kayit(['operating_system' => OperatingSystem::Linux]);
        $windows = $this->kayit(['operating_system' => OperatingSystem::Windows, 'name' => 'win kaydi']);

        $this->assertInstanceOf(SshPasswordDriver::class, $registry->for($linux));
        $this->assertInstanceOf(WindowsPasswordDriver::class, $registry->for($windows));

        /* Kayıt alanı platformun varsayılanını ezer. */
        $linux->update(['rotation_driver' => 'windows']);
        $this->assertInstanceOf(WindowsPasswordDriver::class, $registry->for($linux->fresh()));

        /* Sürücüsü olmayan platform: döndürme engellenir. */
        $portal = $this->kayit(['operating_system' => OperatingSystem::Portal, 'name' => 'portal kaydi']);
        $this->assertNull($registry->for($portal));
        $this->assertStringContainsString('sürücü tanımlı değil', (string) $registry->blockedReason($portal));
    }

    /**
     * Cihazın parola SINIRINA uyulur.
     *
     * Panel ayarı 24 karakter üretiyor olabilir; vCenter SSO 20'den uzununu
     * reddediyor. Sınır uygulanmazsa tur, sebebi ekranda görünmeyen bir
     * "politika" hatasıyla düşerdi.
     */
    #[Test]
    public function surucunun_uzunluk_siniri_uygulanir(): void
    {
        $cihaz = new FakeLimitedPasswordDriver(['root' => 'eski-parola']);

        config([
            'rotation.drivers' => ['ssh' => FakeLimitedPasswordDriver::class],
            'rotation.password_length' => 40,
        ]);
        $this->app->instance(FakeLimitedPasswordDriver::class, $cihaz);

        $credential = $this->kayit();

        app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(20, strlen($cihaz->accounts['root']), 'Parola cihazın sınırına kısaltılmalı.');
    }

    /** Sınır koymayan sürücüde ayar aynen geçerli. */
    #[Test]
    public function sinirsiz_surucude_ayardaki_uzunluk_kullanilir(): void
    {
        config(['rotation.password_length' => 32]);

        $credential = $this->kayit();

        app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(32, strlen($this->device->accounts['root']));
    }

    /**
     * Onarım, kurtarma hesabını GÜNCELLEME KİMLİĞİYLE siler.
     *
     * Onarım çalışan bir GİRİŞ arayarak başlıyor; ama silmek YETKİ ister ve
     * ikisi aynı şey değil. Örnek: vCenter'da kaydın kendi hesabı giriş
     * yapabiliyor, hesap uçlarında 403 alıyor. O kimlikle silmeye
     * çalışan onarım kaydı sonsuza dek yarıda bırakıyordu.
     */
    #[Test]
    public function onarim_kurtarma_hesabini_guncelleme_kimligiyle_siler(): void
    {
        $credential = $this->kayit([
            'rotation_username' => 'yetkili',
            'rotation_password' => 'yetkili-parola',
        ]);

        $this->device->accounts['yetkili'] = 'yetkili-parola';

        /* Tur yarıda kalsın. */
        $this->device->failNewPasswordVerify = true;
        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertTrue($rotation->fresh()->recovery_active);

        /* Kaydın KENDİ hesabı silme yetkisine sahip değil. */
        $this->device->failDeleteRecovery = false;
        $this->device->yetkiliSilme = 'yetkili';

        $kurtarma = (string) $rotation->recovery_username;
        $this->assertArrayHasKey($kurtarma, $this->device->accounts, 'Kurtarma hesabı makinede olmalıydı.');

        $onarilan = app(PasswordRotator::class)->repair($rotation->fresh());

        /*
         * Sahte makine yetkisiz kimlikle silmeyi REDDEDİYOR; hesabın gitmiş
         * olması, silmenin yetkili kimlikle yapıldığının kanıtıdır.
         */
        $this->assertArrayNotHasKey($kurtarma, $this->device->accounts, 'Kurtarma hesabı silinmeliydi.');
        $this->assertFalse($onarilan->fresh()->recovery_active);
    }
}
