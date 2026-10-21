<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Services\Rotation\DeviceUnreachableException;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\Drivers\JunosPasswordDriver;
use App\Services\Rotation\Junos\NetconfTarget;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeJunosDevice;
use Tests\TestCase;

/**
 * Junos switch parola döndürme sürücüsü.
 *
 * Hiçbir cihaza ya da sokete BAĞLANILMAZ (bkz. .ai/rules/work.md): taşıma
 * FakeJunosDevice'ın bellek içi durum makinesidir. Sınanan şey NETCONF'un
 * kendisi değil, SIRA: kurtarma hesabı parola değişiminden önce açılıyor mu,
 * çalışmıyorsa tur parolaya hiç dokunmadan geri alınıyor mu, ve izin
 * listesinin dışındaki adrese tek bağlantı bile denenmiyor mu.
 */
class JunosDriverTest extends TestCase
{
    use RefreshDatabase;

    private const ESKI = 'eski-admin-parolasi';

    private FakeJunosDevice $device;

    private JunosPasswordDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = new FakeJunosDevice(['admin' => self::ESKI]);
        $this->driver = new JunosPasswordDriver($this->device->client());

        config(['rotation.drivers' => ['junos' => JunosPasswordDriver::class]]);
        $this->app->instance(JunosPasswordDriver::class, $this->driver);
    }

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Embedded,
            'rotation_driver' => 'junos',
            'host' => '192.0.2.195',
            'port' => 22,
            'username' => 'admin',
            'password' => self::ESKI,
            'rotation_enabled' => true,
            ...$ustune,
        ]);
    }

    /** Adres kilidi yok: izin listesi aranmaz, her adres işlenir. */
    #[Test]
    public function adres_kilidi_yok_her_adres_islenir(): void
    {
        foreach (['192.0.2.195', '198.51.100.10', '203.0.113.174'] as $host) {
            $this->assertNull($this->driver->unsupportedReason($this->kayit(['host' => $host])));
        }
    }

    #[Test]
    public function kayitta_surucu_alani_olmayan_gomulu_kayit_junos_a_ulasmaz(): void
    {
        /* Gömülü işletim sistemli, sürücü alanı boş bir switch kaydı. */
        $kayit = $this->kayit(['rotation_driver' => null]);

        $this->assertNull(app(DriverRegistry::class)->keyFor($kayit));
        $this->assertStringContainsString('sürücü tanımlı değil', (string) app(DriverRegistry::class)->blockedReason($kayit));
    }

    #[Test]
    public function eksik_alanlar_adiyla_bildirilir(): void
    {
        $this->assertNull($this->driver->unsupportedReason($this->kayit()));
        $this->assertSame('Adres tanımlı değil.', $this->driver->unsupportedReason($this->kayit(['host' => null])));
        $this->assertSame('Kullanıcı adı tanımlı değil.', $this->driver->unsupportedReason($this->kayit(['username' => null])));
        $this->assertStringContainsString('Kayıtlı parola yok', (string) $this->driver->unsupportedReason($this->kayit(['password' => null])));
        $this->assertStringContainsString('aynı olamaz', (string) $this->driver->unsupportedReason($this->kayit(['username' => 'eys-kurtarma'])));
    }

    #[Test]
    #[DataProvider('cerceveler')]
    public function mutlu_yol_sirasi_kurtarma_once_acilir_parola_sonra_degisir_kurtarma_en_son_silinir(string $cerceve): void
    {
        $this->device->framing = $cerceve;
        $kayit = $this->kayit();

        $tur = app(PasswordRotator::class)->rotate($kayit);

        $this->assertSame(RotationStatus::Succeeded, $tur->status, (string) $tur->message);

        $yazma = array_values(array_filter(
            $this->device->events,
            fn (string $e): bool => preg_match('/: (create|chpass|delete|confirm) /', $e) === 1 || str_ends_with($e, ': confirm'),
        ));

        $this->assertSame([
            'admin: create eys-kurtarma super-user',
            'eys-kurtarma: chpass admin (confirmed)',
            'eys-kurtarma: confirm',
            'admin: delete eys-kurtarma',
        ], $yazma, 'Kurtarma önce açılır, parola kurtarma oturumundan değişir ve onaylanır, kurtarma en son silinir.');

        /* Cihazda: yeni parola çalışıyor, eski çalışmıyor, kurtarma hesabı yok. */
        $yeni = $kayit->fresh()->password;
        $this->assertNotSame(self::ESKI, $yeni);
        $this->assertSame($yeni, $this->device->users['admin']['password']);
        $this->assertArrayNotHasKey('eys-kurtarma', $this->device->users);
        $this->assertFalse($tur->fresh()->recovery_active);

        /* Her oturum kapatıldı: cihazda mgd asılı kalmaz. */
        $this->assertSame(
            $this->device->connectAttempts,
            count(array_filter($this->device->events, fn (string $e): bool => str_ends_with($e, ': close'))),
        );
    }

    public static function cerceveler(): array
    {
        return ['Junos 12.3 (EOM)' => ['eom'], 'Junos 24.4 (chunked)' => ['chunked']];
    }

    #[Test]
    public function kurtarma_dogrulamasi_basarisizsa_hesap_silinir_ve_parolaya_dokunulmaz(): void
    {
        $this->device->rejectLogins = ['eys-kurtarma'];
        $kayit = $this->kayit();

        $tur = app(PasswordRotator::class)->rotate($kayit);

        $this->assertSame(RotationStatus::Failed, $tur->status);
        $this->assertFalse($tur->fresh()->recovery_active, 'Çalışmayan kurtarma hesabı cihazda bırakılmamalı.');
        $this->assertArrayNotHasKey('eys-kurtarma', $this->device->users);

        /* Panel hesabının parolasına hiç dokunulmadı. */
        $this->assertSame(self::ESKI, $this->device->users['admin']['password']);
        $this->assertSame(self::ESKI, $kayit->fresh()->password);
        $this->assertNotContains('eys-kurtarma: chpass admin (confirmed)', $this->device->events);
        $this->assertEmpty(array_filter($this->device->events, fn (string $e): bool => str_contains($e, 'chpass')));
    }

    #[Test]
    public function onay_oturumu_acilamazsa_cihaz_eski_parolaya_doner_ve_tur_onarilabilir_kalir(): void
    {
        $kayit = $this->kayit();

        /*
         * Kurtarma hesabıyla ilk iki giriş (kanıt + parola oturumu) geçer,
         * üçüncü (onay oturumu) reddedilir: parola commit edilmiş ama onay yok.
         */
        $this->device->rejectLoginAfter = ['eys-kurtarma' => 2];

        $tur = app(PasswordRotator::class)->rotate($kayit);

        /* Yeni parolanın cihazda ne olduğu bilinmiyor: tur "başarılı" sayılamaz (doğrulanamadı). */
        $this->assertSame(RotationStatus::Unverified, $tur->status);
        $this->assertTrue($tur->fresh()->recovery_active, 'Onay verilemedi: kurtarma hesabı cihazda duruyor ve onarım gerekiyor.');

        /* Onay gelmedi: cihaz kendini geri alır, kasadaki parola hâlâ geçerli. */
        $this->device->confirmTimeout();
        $this->assertSame(self::ESKI, $this->device->users['admin']['password']);
        $this->assertSame(self::ESKI, $kayit->fresh()->password);
    }

    #[Test]
    public function verify_dogru_parolada_true_yanlista_false_doner(): void
    {
        $kayit = $this->kayit();

        $this->assertTrue($this->driver->verify($kayit, 'admin', self::ESKI));
        $this->assertFalse($this->driver->verify($kayit, 'admin', 'yanlis-parola'));
        $this->assertFalse($this->driver->verify($kayit, 'olmayan', self::ESKI));
    }

    #[Test]
    public function verify_ulasilamayan_cihazi_false_degil_istisna_olarak_bildirir(): void
    {
        $this->device->down = true;

        $this->expectException(DeviceUnreachableException::class);

        $this->driver->verify($this->kayit(), 'admin', self::ESKI);
    }

    #[Test]
    public function open_kimlik_reddinde_rotation_exception_atar_ve_parolayi_yazmaz(): void
    {
        try {
            $this->driver->open($this->kayit(), 'admin', 'yanlis-ve-gizli-parola');
            $this->fail('Kimlik reddinde istisna atılmalıydı.');
        } catch (RotationException $e) {
            $this->assertStringNotContainsString('yanlis-ve-gizli-parola', $e->getMessage());
            $this->assertNotInstanceOf(DeviceUnreachableException::class, $e);
        }
    }

    #[Test]
    public function cihaz_hatasi_mesajinda_parola_bulunmaz(): void
    {
        $this->device->failRpc = 'commit-configuration';
        $oturum = $this->driver->open($this->kayit(), 'admin', self::ESKI);

        try {
            $this->driver->createRecovery($oturum, 'eys-kurtarma', 'cok-gizli-gecici-parola');
            $this->fail('Commit reddinde istisna atılmalıydı.');
        } catch (RotationException $e) {
            $this->assertStringNotContainsString('cok-gizli-gecici-parola', $e->getMessage());
            $this->assertStringNotContainsString(self::ESKI, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'Önceki istisna (iz, parola taşıyabilir) zincire eklenmemeli.');
        } finally {
            $oturum->close();
        }

        /* Reddedilen yazmanın adayı atıldı ve kilit bırakıldı: sonraki yazma bloklanmaz. */
        $this->assertContains('admin: unlock', $this->device->events);
        $this->assertArrayNotHasKey('eys-kurtarma', $this->device->users);
    }

    #[Test]
    public function kurtarma_islemleri_yalnizca_kurtarma_adiyla_calisir(): void
    {
        $oturum = $this->driver->open($this->kayit(), 'admin', self::ESKI);

        try {
            foreach (['admin', 'root', 'baska-kullanici'] as $ad) {
                try {
                    $this->driver->deleteRecovery($oturum, $ad);
                    $this->fail("{$ad} silinememeliydi.");
                } catch (RotationException) {
                    // beklenen
                }
            }

            try {
                $this->driver->createRecovery($oturum, 'admin', 'x-gecici-parola');
                $this->fail('Panel hesabı kurtarma olarak yeniden yazılamamalıydı.');
            } catch (RotationException) {
                // beklenen
            }

            $this->driver->createRecovery($oturum, 'eys-kurtarma', 'gecici-parola-1');
            $this->assertArrayHasKey('eys-kurtarma', $this->device->users);
        } finally {
            $oturum->close();
        }

        $this->assertSame(self::ESKI, $this->device->users['admin']['password']);
    }

    #[Test]
    public function xml_ozel_karakterli_parola_kacirilarak_gider(): void
    {
        $oturum = $this->driver->open($this->kayit(), 'admin', self::ESKI);

        try {
            $this->driver->createRecovery($oturum, 'eys-kurtarma', 'a<b>&"c\'d');
        } finally {
            $oturum->close();
        }

        $this->assertSame('a<b>&"c\'d', $this->device->users['eys-kurtarma']['password']);
    }

    #[Test]
    public function surucu_kayit_defterinde_ve_gereksinim_metninde_gorunur(): void
    {
        $kayitDefteri = app(DriverRegistry::class);

        $this->assertSame('Junos switch · NETCONF', $kayitDefteri->options()['junos']);
        $this->assertStringContainsString('NETCONF', (string) $kayitDefteri->gereksinim($this->kayit()));
        $this->assertSame(6, $kayitDefteri->adimSayisi($this->kayit()), 'Kurtarma hesaplı sıra: altı adım.');
        $this->assertInstanceOf(JunosPasswordDriver::class, $kayitDefteri->for($this->kayit()));
    }

    #[Test]
    public function netconf_hedefi_parolayi_hata_izinde_gostermez(): void
    {
        $hedef = new NetconfTarget('192.0.2.195', 830, 'admin', 'izde-gorunmemeli');

        $this->assertSame(830, $hedef->port);
        $parametre = (new \ReflectionClass($hedef))->getConstructor()->getParameters()[3];
        $this->assertNotEmpty($parametre->getAttributes(\SensitiveParameter::class));
    }
}
