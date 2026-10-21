<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\RedfishPasswordDriver;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * iLO / Redfish sürücüsü.
 *
 * Canlı bir iLO'ya BAĞLANILMAZ; yanıt biçimleri taklit edilir. Taklit iLO 4
 * (fw 2.78 ve 2.81) biçimini izler: hesap belgesinde RoleId yok, yetkiler
 * Oem.Hp.Privileges altında duruyor. Taklit bu ayrıntıyı içermeseydi testler
 * geçerken cihaz isteği "Base.0.10.PropertyUnknown" ile reddederdi.
 */
class RedfishDriverTest extends TestCase
{
    use RefreshDatabase;

    /** iLO 4: RoleId YOK, yetki Oem.Hp altında. */
    private const ILO4 = [
        'Id' => '1',
        'UserName' => 'Administrator',
        '@odata.etag' => 'W/"21C260DB"',
        'Password' => null,
        'Oem' => ['Hp' => [
            'LoginName' => 'Administrator',
            'Privileges' => ['LoginPriv' => true, 'UserConfigPriv' => true],
        ]],
    ];

    /** iLO 5 (1.40+): RoleId var; Oem anahtarı da "Hp" değil "Hpe". */
    private const ILO5 = [
        'Id' => '1',
        'UserName' => 'Administrator',
        '@odata.etag' => 'W/"21C260DB"',
        'Password' => null,
        'RoleId' => 'Administrator',
        'Oem' => ['Hpe' => [
            'LoginName' => 'Administrator',
            'Privileges' => ['LoginPriv' => true, 'UserConfigPriv' => true],
        ]],
    ];

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Bmc,
            'host' => '198.51.100.30',
            'port' => 443,
            'url' => null,
            'username' => 'Administrator',
            'password' => 'eski-parola',
            ...$ustune,
        ]);
    }

    /**
     * Sahte iLO.
     *
     * Yanıt YÖNTEME göre seçilir, yalnızca adrese göre değil: hesap
     * koleksiyonu hem okunuyor hem üzerine POST ediliyor ve ikisinin yanıtı
     * aynı olamaz.
     */
    private function ilo(array $hesap = self::ILO4, mixed $postYaniti = null): void
    {
        Http::fake(function ($request) use ($hesap, $postYaniti) {
            $url = $request->url();

            if ($request->method() === 'POST') {
                return $postYaniti ?? Http::response([], 201);
            }

            return match (true) {
                str_ends_with($url, '/Accounts/1/') => Http::response($hesap, 200),
                str_ends_with($url, '/Accounts/14/') => Http::response([
                    'Id' => '14',
                    'UserName' => 'eys-kurtarma-abc123',
                    '@odata.etag' => 'W/"6C16FDE3"',
                ], 200),
                str_ends_with($url, '/Accounts/') => Http::response([
                    'Members' => [
                        ['@odata.id' => '/redfish/v1/AccountService/Accounts/1/'],
                        ['@odata.id' => '/redfish/v1/AccountService/Accounts/14/'],
                    ],
                ], 200),
                default => Http::response([], 404),
            };
        });
    }

    #[Test]
    public function eksik_alanlari_adiyla_bildirir(): void
    {
        $driver = new RedfishPasswordDriver;

        $this->assertSame('Adres tanımlı değil.', $driver->unsupportedReason($this->kayit(['host' => null, 'url' => null])));
        $this->assertSame('Kullanıcı adı tanımlı değil.', $driver->unsupportedReason($this->kayit(['username' => null])));
        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function kimlik_basic_auth_ile_gonderilir(): void
    {
        $this->ilo();

        (new RedfishPasswordDriver)->open($this->kayit(), 'Administrator', 'eski-parola');

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization')
            && str_starts_with($r->header('Authorization')[0], 'Basic '));
    }

    #[Test]
    public function reddedilen_kimlikte_dogrulama_false_doner(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unauthorized']], 401)]);

        $this->assertFalse((new RedfishPasswordDriver)->verify($this->kayit(), 'Administrator', 'yanlis'));
    }

    #[Test]
    public function parola_kullanici_adiyla_degil_sayisal_kimlikle_degistirilir(): void
    {
        $this->ilo();

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->changePassword($session, 'Administrator', 'yeni-parola');

        /*
         * iLO hesapları "1", "14" gibi SAYISAL kimlikle adreslenir. Kullanıcı
         * adıyla PATCH etmek 404 verirdi; bu test o eşlemeyi kilitliyor.
         */
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/redfish/v1/AccountService/Accounts/1/')
            && $r['Password'] === 'yeni-parola');
    }

    #[Test]
    public function degisiklikte_etag_geri_gonderilir(): void
    {
        $this->ilo();

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->changePassword($session, 'Administrator', 'yeni-parola');

        /* Aynı anda başka yerden değişmiş bir hesabın üzerine yazmayalım. */
        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && $r->hasHeader('If-Match', 'W/"21C260DB"'));
    }

    /**
     * iLO 4'te yetki RoleId ile VERİLEMEZ.
     *
     * RoleId gönderilince hesap hiç açılmıyor, tur daha ikinci adımda
     * duruyor.
     */
    #[Test]
    public function ilo4te_kurtarma_hesabi_oem_yetkileriyle_acilir(): void
    {
        $this->ilo(self::ILO4);

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'gecici-parola');

        Http::assertSent(function ($r) {
            if ($r->method() !== 'POST') {
                return false;
            }

            $yetki = $r['Oem']['Hp']['Privileges'] ?? [];

            return $r['UserName'] === 'eys-kurtarma-abc123'
                && ! isset($r['RoleId'])
                && ($yetki['LoginPriv'] ?? false) === true
                && ($yetki['UserConfigPriv'] ?? false) === true;
        });
    }

    /** iLO 5'te aynı iş RoleId ile yapılır; Oem'e düşülmez. */
    #[Test]
    public function ilo5te_kurtarma_hesabi_rol_ile_acilir(): void
    {
        $this->ilo(self::ILO5);

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'gecici-parola');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/redfish/v1/AccountService/Accounts/')
            && $r['RoleId'] === 'Administrator'
            && ! isset($r['Oem']));
    }

    /** Yetki alanı hiç bulunamazsa sessizce yetkisiz hesap açılmaz. */
    #[Test]
    public function taninmayan_hesap_biciminde_hesap_acilmaz(): void
    {
        $this->ilo(['Id' => '1', 'UserName' => 'Administrator']);

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $this->expectExceptionMessage('iLO hesap biçimi tanınmadı');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'gecici-parola');
    }

    #[Test]
    public function kurtarma_hesabi_sayisal_kimlikle_silinir(): void
    {
        $this->ilo();

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $driver->deleteRecovery($session, 'eys-kurtarma-abc123');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/redfish/v1/AccountService/Accounts/14/'));
    }

    #[Test]
    public function olmayan_hesap_acikca_bildirilir(): void
    {
        $this->ilo();

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        $this->expectExceptionMessage("iLO'da 'yok-boyle-biri' adlı hesap bulunamadı.");

        $driver->changePassword($session, 'yok-boyle-biri', 'yeni');
    }

    #[Test]
    public function ilo_hata_govdesi_mesaja_tasinir(): void
    {
        $this->ilo(self::ILO4, Http::response([
            'error' => ['@Message.ExtendedInfo' => [['MessageId' => 'Base.1.0.PasswordComplexity']]],
        ], 400));

        $driver = new RedfishPasswordDriver;
        $session = $driver->open($this->kayit(), 'Administrator', 'eski-parola');

        try {
            $driver->createRecovery($session, 'eys-kurtarma-abc123', 'kisa');
            $this->fail('İstisna atmalıydı.');
        } catch (RotationException $e) {
            /* iLO nedeni gövdede anlatır; yalnızca duruma bakmak onu kaybederdi. */
            $this->assertStringContainsString('PasswordComplexity', $e->getMessage());
        }
    }
}
