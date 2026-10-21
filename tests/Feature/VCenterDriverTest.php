<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\VCenterPasswordDriver;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * vCenter appliance sürücüsü.
 *
 * Canlı bir vCenter'a BAĞLANILMAZ; taklit edilen biçimler vCenter 8.0.3'ün
 * davranışını izler. Resmi SDK açıklaması üç noktada yanıltıcı ve üçü de
 * sessizce başarısız oluyordu:
 *
 *  - Hesap AÇMA yalnızca eski /rest ad alanında var; /api aynı isteğe 404.
 *  - Parola değiştirme gövdesi DÜZ; {"config": {...}} sarmalı 400 alıyor.
 *  - `old_password` HER ZAMAN gerekiyor; SDK "superAdmin başkasınınkini
 *    değiştirirken istenmez" diyor ama cihaz reddediyor.
 */
class VCenterDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '198.51.100.20',
            'port' => 443,
            'url' => null,
            'username' => 'root',
            'password' => 'eski-parola',

            /* Değiştiren kimlik SSO olmak zorunda; bkz. supportsRecovery. */
            'rotation_username' => 'administrator@vsphere.local',
            'rotation_password' => 'sso-parolasi',
            ...$ustune,
        ]);
    }

    private function vcenter(array $ekstra = []): void
    {
        Http::fake([
            '*/api/session' => Http::response('"jeton-abc123"', 201),
            '*/api/appliance/local-accounts/root' => Http::response([
                'roles' => ['superAdmin'], 'enabled' => true, 'fullname' => 'root',
            ], 200),

            /* open() yetkiyi de sınar: koleksiyon 403 dönerse kimlik yetersizdir. */
            '*/api/appliance/local-accounts/' => Http::response(['root'], 200),
            ...$ekstra,
            '*' => Http::response('', 204),
        ]);
    }

    #[Test]
    public function sso_hesabi_bu_surucunun_kapsaminda_degil(): void
    {
        /*
         * SSO kullanıcıları vmdir'de durur; bu uçlar onlara dokunmaz.
         * Sessizce denemek, yapılmayan bir işi yapılmış sanmak olurdu.
         */
        $this->assertStringContainsString(
            'SSO hesabı',
            (string) (new VCenterPasswordDriver)->unsupportedReason(
                $this->kayit(['username' => 'administrator@vsphere.local']),
            ),
        );

        $this->assertNull((new VCenterPasswordDriver)->unsupportedReason($this->kayit()));
    }

    /**
     * Güncelleme hesabı SSO olmalı — ve bu ÖNCEDEN söylenmeli.
     *
     * Boş bırakılırsa kaydın kendi hesabı (root) kullanılır; ÖLÇÜLDÜ: yerel
     * hesap uçlarını hiçbir yerel hesap kullanamıyor, root bile 403 alıyor.
     * Panel bunu söylemeseydi kayıt "hazır" görünür, tur ilk adımda
     * anlaşılmaz bir yetki hatasıyla düşerdi.
     */
    #[Test]
    public function guncelleme_hesabi_sso_degilse_engellenir(): void
    {
        $driver = new VCenterPasswordDriver;

        $this->assertStringContainsString(
            'yalnızca SSO yöneticisi',
            (string) $driver->unsupportedReason($this->kayit(['rotation_username' => null])),
        );

        $this->assertStringContainsString(
            'SSO yöneticisi olmalı',
            (string) $driver->unsupportedReason($this->kayit(['rotation_username' => 'root'])),
        );
    }

    /**
     * Bu platformda kurtarma hesabı AÇILMAZ.
     *
     * Açılacak hesap yerel olurdu ve yerel hesap bu uçları kullanamıyor:
     * tur, işe yaramayan bir hesap açıp yarıda kalırdı.
     */
    #[Test]
    public function kurtarma_hesabi_acilmaz(): void
    {
        $this->assertFalse((new VCenterPasswordDriver)->supportsRecovery());
        $this->assertTrue((new VCenterPasswordDriver)->canVerify());
    }

    /**
     * Yetkisiz kimlik EN BAŞTA durdurulur.
     *
     * Oturum jetonu almak yetmiyor: root jeton alabiliyor ama hesap
     * uçlarında 403 yiyor. Hata, henüz hiçbir şey değişmemişken ve insanca
     * söylenmeli.
     */
    #[Test]
    public function yetkisiz_kimlikle_oturum_acilmaz(): void
    {
        Http::fake([
            '*/api/session' => Http::response('"jeton-abc123"', 201),
            '*/api/appliance/local-accounts/' => Http::response([
                'error_type' => 'UNAUTHORIZED',
                'messages' => [['default_message' => 'Unable to authorize user']],
            ], 403),
        ]);

        $this->expectExceptionMessage('appliance yerel hesaplarını yönetemiyor');

        (new VCenterPasswordDriver)->open($this->kayit(), 'root', 'eski-parola');
    }

    #[Test]
    public function oturum_basic_auth_ile_acilir_ve_jeton_basliga_konur(): void
    {
        $this->vcenter();

        $driver = new VCenterPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/session')
            && $r->hasHeader('Authorization'));

        $driver->changePassword($session, 'root', 'yeni-parola');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && $r->hasHeader('vmware-api-session-id', 'jeton-abc123'));
    }

    #[Test]
    public function kurtarma_hesabi_hedefin_rolleriyle_acilir(): void
    {
        $this->vcenter();

        $driver = new VCenterPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        $driver->createRecovery($session, 'eys-kurtarma-abc', 'gecici');

        /*
         * Rol adı koda gömülmez; hedef hesaptan okunup kopyalanır. Böylece
         * kurulumdan kuruluma değişen bir kimliğe bel bağlanmaz.
         */
        Http::assertSent(fn ($r) => str_contains($r->url(), 'local-accounts/root') && $r->method() === 'GET');

        /*
         * Hesap AÇMA eski ad alanındadır: ÖLÇÜLDÜ, /api aynı isteğe 404
         * döndürüyor. Sarmal ("config") da yalnızca burada isteniyor.
         */
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/rest/appliance/local-accounts/eys-kurtarma-abc')
            && $r['config']['roles'] === ['superAdmin']
            && $r['config']['password'] === 'gecici');
    }

    /**
     * Parola gövdesi DÜZ yazılır ve eski parola HER ZAMAN gider.
     *
     * ÖLÇÜLDÜ: {"config": {...}} sarmalı 400 "Unexpected property with name:
     * {'config'}" alıyor; sarmalsız ama eski parolasız istek ise 500 "Old
     * password is required to reset the password." diyor. SDK açıklamasına
     * güvenip eski parolayı göndermeseydik tur, parola değişmemişken
     * değiştiğini sanacağı bir yerde düşerdi.
     */
    #[Test]
    public function parola_duz_govdeyle_ve_eski_parolayla_degistirilir(): void
    {
        $this->vcenter();

        $driver = new VCenterPasswordDriver;

        /* Değişimi SSO oturumu yapar; eski parola KASADAN gelir. */
        $session = $driver->open($this->kayit(), 'administrator@vsphere.local', 'sso-parolasi');

        $driver->changePassword($session, 'root', 'yeni-parola');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/api/appliance/local-accounts/root')
            && ! isset($r['config'])
            && $r['password'] === 'yeni-parola'
            && $r['old_password'] === 'eski-parola');
    }

    /** Kendi parolasını değiştiren oturumda eski parola oturumun kendisidir. */
    #[Test]
    public function kendi_parolasini_degistiren_oturum_kendi_parolasini_gonderir(): void
    {
        $this->vcenter();

        $driver = new VCenterPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'oturum-parolasi');

        $driver->changePassword($session, 'root', 'yeni-parola');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && $r['old_password'] === 'oturum-parolasi');
    }

    /**
     * Parolası BİLİNMEYEN hesap değiştirilmeye çalışılmaz.
     *
     * vCenter eski parolayı zorunlu tutuyor; elimizde yoksa istek zaten
     * reddedilirdi. Sessizce boş göndermek yerine nedeni söylenir.
     */
    #[Test]
    public function parolasi_bilinmeyen_hesap_degistirilmez(): void
    {
        $this->vcenter();

        $driver = new VCenterPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator@vsphere.local', 'sso-parolasi');

        $this->expectExceptionMessage('mevcut parolası bilinmiyor');

        $driver->changePassword($session, 'baska-hesap', 'yeni-parola');
    }

    #[Test]
    public function rolsuz_hedefte_kurtarma_hesabi_acilmaz(): void
    {
        Http::fake([
            '*/api/session' => Http::response('"jeton"', 201),
            '*/api/appliance/local-accounts/root' => Http::response(['roles' => []], 200),
            '*' => Http::response('', 204),
        ]);

        $driver = new VCenterPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski-parola');

        /* Yetkisiz kurtarma hesabı güvence değil, açıkta duran fazladan yetki. */
        $this->expectExceptionMessage('yetkisiz kalırdı');

        $driver->createRecovery($session, 'eys-kurtarma-abc', 'gecici');
    }

    #[Test]
    public function hata_govdesindeki_neden_mesaja_tasinir(): void
    {
        Http::fake([
            '*/api/session' => Http::response('"jeton"', 201),
            '*/api/appliance/local-accounts/root' => Http::response([
                'messages' => [['default_message' => 'Password does not meet complexity requirements.']],
            ], 400),
            '*' => Http::response('', 204),
        ]);

        $driver = new VCenterPasswordDriver;
        $session = $driver->open($this->kayit(), 'root', 'eski');

        try {
            $driver->changePassword($session, 'root', 'kisa');
            $this->fail('İstisna atmalıydı.');
        } catch (RotationException $e) {
            $this->assertStringContainsString('complexity', $e->getMessage());
        }
    }

    #[Test]
    public function yanlis_kimlikte_dogrulama_false_doner(): void
    {
        Http::fake(['*' => Http::response(['error_type' => 'UNAUTHENTICATED'], 401)]);

        $this->assertFalse((new VCenterPasswordDriver)->verify($this->kayit(), 'root', 'yanlis'));
    }
}
