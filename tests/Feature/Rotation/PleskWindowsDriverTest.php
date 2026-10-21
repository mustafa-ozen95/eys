<?php

declare(strict_types=1);

namespace Tests\Feature\Rotation;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\Drivers\PleskWindowsPasswordDriver;
use App\Services\Rotation\PowerShellGizli;
use App\Services\Rotation\RotationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WINDOWS ÜZERİNDEKİ PLESK.
 *
 * `plesk` sürücüsü makineye SSH ile bağlanıp Linux'a özgü bir ikili
 * çalıştırıyor. Windows üzerindeki Plesk'te bunların hiçbiri olmayabilir:
 * SSH portu kapalı, yalnızca WinRM (5985) açık. Böyle bir kayıt Linux
 * sürücüsüyle hiçbir zaman çalışamaz.
 *
 * Parolanın nasıl geçirileceğini Plesk'in kendi yardımı söylüyor (plesk bin
 * admin --help):
 *
 *   "we recommend that you pass non-encrypted passwords using the environment
 *    variable PSA_PASSWORD … specify an empty value … (like ' -passwd "" ')"
 */
class PleskWindowsDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $alanlar = []): Credential
    {
        $grup = CredentialGroup::factory()->create(['name' => 'srv-b']);

        return Credential::factory()->for($grup, 'group')->create(array_merge([
            'name' => 'Plesk Panel',
            'host' => 'srv-b.ornek.test',
            'port' => 8443,
            'access_type' => AccessType::Web,
            'username' => 'admin',
            'password' => 'panel-parolasi',
            'rotation_driver' => 'plesk_windows',
        ], $alanlar));
    }

    #[Test]
    public function surucu_listede_ve_adiyla_duruyor(): void
    {
        $secenekler = app(DriverRegistry::class)->options();

        $this->assertArrayHasKey('plesk_windows', $secenekler);
        $this->assertStringContainsString('Windows', $secenekler['plesk_windows']);
    }

    /** Kaydın sürücüsü buysa, çözülen sürücü de bu olmalı. */
    #[Test]
    public function kayit_icin_bu_surucu_secilir(): void
    {
        $this->assertInstanceOf(
            PleskWindowsPasswordDriver::class,
            app(DriverRegistry::class)->for($this->kayit()),
        );
    }

    /**
     * KASADAN RDP KAYDI SEÇİLİR.
     *
     * Windows makinede "yönetim kaydı" pratikte RDP kaydıdır; liste sabit
     * "ssh" arasaydı boş çıkardı.
     */
    #[Test]
    public function listede_rdp_kaydi_cikar(): void
    {
        $kayit = $this->kayit();

        $rdp = Credential::factory()->for($kayit->group, 'group')->create([
            'name' => 'Uzak Masaüstü',
            'access_type' => AccessType::Rdp,
            'username' => 'administrator',
            'password' => 'windows-parolasi',
            'port' => 3390,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertSee('#'.$rdp->id.' · srv-b · Uzak Masaüstü', false);
    }

    /**
     * SÜRÜCÜ HENÜZ KAYDEDİLMEMİŞKEN DE LİSTE DOLU.
     *
     * Liste kayıtlı sürücüye göre daraltılıyordu ve kısır döngü çıkıyordu:
     * kullanıcı sürücüyü "Plesk · Windows" seçiyor ama liste hâlâ eski
     * sürücüye (Linux) göre kurulduğu için yalnızca SSH kayıtlarını
     * içeriyordu — Windows makinede SSH yoksa liste boş kalıyordu ve uzak
     * masaüstü kaydı seçilemiyordu.
     */
    #[Test]
    public function kayitli_surucu_linux_olsa_da_rdp_kaydi_listelenir(): void
    {
        $kayit = $this->kayit(['rotation_driver' => 'plesk']);

        $rdp = Credential::factory()->for($kayit->group, 'group')->create([
            'name' => 'Uzak Masaüstü',
            'access_type' => AccessType::Rdp,
            'username' => 'administrator',
            'password' => 'windows-parolasi',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertSee('#'.$rdp->id.' · srv-b · Uzak Masaüstü', false);
    }

    /** Seçilmediyse tur başlatılmaz ve gerekçe RDP kaydını önerir. */
    #[Test]
    public function secilmediyse_sebep_yazilir(): void
    {
        /* Öneriyi bakanın görebildiği kayıtlar arasından seçer: bakan yoksa öneri de yok. */
        $this->actingAs(User::factory()->admin()->create());

        $kayit = $this->kayit();

        $rdp = Credential::factory()->for($kayit->group, 'group')->create([
            'name' => 'Uzak Masaüstü',
            'host' => $kayit->host,
            'access_type' => AccessType::Rdp,
            'username' => 'administrator',
            'password' => 'windows-parolasi',
        ]);

        $sebep = app(DriverRegistry::class)->blockedReason($kayit->fresh());

        $this->assertNotNull($sebep);
        $this->assertStringContainsString('#'.$rdp->id, (string) $sebep);

        $kayit->forceFill(['rotation_credential_id' => $rdp->id])->save();

        $this->assertNull(app(DriverRegistry::class)->blockedReason($kayit->fresh()));
    }

    /**
     * UYUMSUZ EŞLEŞME MAKİNEYE GİTMEDEN DURUR.
     *
     * Liste artık bütün yönetim türlerini gösteriyor (daraltmak kısır döngü
     * üretiyordu), dolayısıyla uyumsuz bir eşleşme kurulabiliyor: sürücü
     * Linux/SSH, seçilen hesap RDP kaydı. Denetim olmasa panel bunu makinenin
     * karşısında öğrenirdi — "Error reading SSH identification string" diye.
     * Artık ekranda, ne yapılacağını söyleyerek duruyor.
     */
    #[Test]
    public function surucuye_uymayan_hesap_reddedilir(): void
    {
        $kayit = $this->kayit(['rotation_driver' => 'plesk']);   /* Linux: SSH ister */

        $rdp = Credential::factory()->for($kayit->group, 'group')->create([
            'name' => 'Uzak Masaüstü',
            'access_type' => AccessType::Rdp,
            'username' => 'administrator',
            'password' => 'windows-parolasi',
        ]);

        $kayit->forceFill(['rotation_credential_id' => $rdp->id])->save();

        $sebep = app(DriverRegistry::class)->blockedReason($kayit->fresh());

        $this->assertNotNull($sebep);
        $this->assertStringContainsString('uymuyor', (string) $sebep);
        $this->assertStringContainsString('Uzak Masaüstü', (string) $sebep);
        $this->assertStringContainsString('SSH', (string) $sebep);
    }

    /** Windows sürücüsünde aynı kayıt kabul edilir. */
    #[Test]
    public function windows_surucusunde_rdp_kaydi_kabul_edilir(): void
    {
        $kayit = $this->kayit();

        $rdp = Credential::factory()->for($kayit->group, 'group')->create([
            'name' => 'Uzak Masaüstü',
            'access_type' => AccessType::Rdp,
            'username' => 'administrator',
            'password' => 'windows-parolasi',
        ]);

        $kayit->forceFill(['rotation_credential_id' => $rdp->id])->save();

        $this->assertNull(app(DriverRegistry::class)->blockedReason($kayit->fresh()));
    }

    /**
     * WINRM PORTU KAYITTAN GELMEZ.
     *
     * Kaydın portu panelin portu (8443), seçilen RDP kaydınınki 3390; WinRM
     * ikisine de bağlanmaz.
     */
    #[Test]
    public function winrm_portu_kayittan_gelmez(): void
    {
        $kayit = $this->kayit();

        $yansima = new \ReflectionMethod(PleskWindowsPasswordDriver::class, 'winRmPort');

        $this->assertSame(5985, $yansima->invoke(new PleskWindowsPasswordDriver, $kayit));
    }

    /**
     * PAROLA BETİĞE GİRMEZ, PSA_PASSWORD'E KONUR.
     *
     * Komut satırına yazılsaydı hedef makinenin süreç listesinde görünürdü.
     */
    #[Test]
    public function parola_ortam_degiskeninden_gecer(): void
    {
        $surucu = new class extends PleskWindowsPasswordDriver
        {
            public string $betik = '';

            public string $girdi = '';

            protected function runPowerShell(RotationSession $session, string $script, string $failure, string $gizli): void
            {
                $this->betik = $script;
                $this->girdi = $gizli;
            }
        };

        $oturum = new RotationSession($this->kayit(), 'administrator', 'windows-parolasi', static fn () => null);

        $surucu->changePassword($oturum, 'admin', 'YENI-PAROLA-42');

        $this->assertStringContainsString('$env:PSA_PASSWORD', $surucu->betik);
        $this->assertStringContainsString('plesk bin admin --set-password', $surucu->betik);

        /*
         * KOMUT cmd.exe ÜZERİNDEN: PowerShell 5.1 boş argümanı düşürüyor ve
         * Plesk `-passwd`'ü değersiz görüp 1 ile dönüyor.
         */
        $this->assertStringContainsString('cmd.exe /c', $surucu->betik);
        $this->assertStringContainsString('-passwd ""', $surucu->betik);

        /* Parola betikte YOK: yerinde yalnızca yer tutucu duruyor. */
        $this->assertStringNotContainsString('YENI-PAROLA-42', $surucu->betik);
        $this->assertStringContainsString(PowerShellGizli::TOKEN, $surucu->betik);
        $this->assertSame('YENI-PAROLA-42', $surucu->girdi);
    }

    /** Bu sürücü yalnızca admin hesabını değiştirir. */
    #[Test]
    public function admin_disinda_hesap_degistirilmez(): void
    {
        $this->assertStringContainsString(
            'yalnızca Plesk yönetici hesabını',
            (string) (new PleskWindowsPasswordDriver)->unsupportedReason($this->kayit(['username' => 'baskasi'])),
        );
    }
}
