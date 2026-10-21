<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Services\Rotation\Drivers\MailcowPasswordDriver;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * mailcow sürücüsü.
 *
 * Canlı bir mailcow'a BAĞLANILMAZ; API yanıtları taklit edilir
 * (bkz. .ai/rules/work.md). Sınanan şey: sürücü mailcow'un gerçekten
 * döndürdüğü biçimleri doğru okuyor mu.
 */
class MailcowDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Application,
            'platform' => 'Mailcow',
            'rotation_driver' => MailcowPasswordDriver::KEY,
            'rotation_enabled' => true,
            'url' => 'https://posta.ornek.test',
            'host' => null,
            'username' => 'alanyoneticisi',
            'password' => 'eski-parola',
            'rotation_password' => 'API-ANAHTARI',
            ...$ustune,
        ]);
    }

    #[Test]
    public function eksik_alanlari_adiyla_bildirir(): void
    {
        $driver = new MailcowPasswordDriver;

        $this->assertSame('Panel adresi (bağlantı adresi) tanımlı değil.',
            $driver->unsupportedReason($this->kayit(['url' => null])));
        $this->assertSame('Güncelleme parolası alanına mailcow API anahtarı girilmeli.',
            $driver->unsupportedReason($this->kayit(['rotation_password' => null])));
        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function gecersiz_api_anahtari_daha_ilk_adimda_yakalanir(): void
    {
        Http::fake(['*/api/v1/get/status/version' => Http::response('Unauthorized', 401)]);

        $driver = new MailcowPasswordDriver;

        $this->expectException(RotationException::class);

        $driver->open($this->kayit(), 'alanyoneticisi', 'YANLIS-ANAHTAR');
    }

    #[Test]
    public function api_anahtari_dogru_baslikta_gonderilir(): void
    {
        Http::fake(['*' => Http::response(['version' => '2024-01'], 200)]);

        (new MailcowPasswordDriver)->open($this->kayit(), 'alanyoneticisi', 'API-ANAHTARI');

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'API-ANAHTARI'));
    }

    #[Test]
    public function mailcow_200_dondurse_bile_govdedeki_hata_yakalanir(): void
    {
        /*
         * mailcow hatalarını da HTTP 200 ile döndürür. Yalnızca durum koduna
         * bakan bir sürücü, başarısız bir değişikliği başarılı sayardı.
         */
        Http::fake([
            '*/api/v1/get/status/version' => Http::response(['version' => '2024-01'], 200),
            '*/api/v1/edit/domain-admin' => Http::response([['type' => 'danger', 'msg' => ['password_policy']]], 200),
        ]);

        $credential = $this->kayit();
        $driver = new MailcowPasswordDriver;
        $session = $driver->open($credential, 'alanyoneticisi', 'API-ANAHTARI');

        $this->expectException(RotationException::class);

        $driver->changePassword($session, 'alanyoneticisi', 'yeni-parola');
    }

    #[Test]
    public function basarili_degisimde_dogru_govde_gonderilir(): void
    {
        Http::fake([
            '*/api/v1/get/status/version' => Http::response(['version' => '2024-01'], 200),
            '*/api/v1/edit/domain-admin' => Http::response([['type' => 'success']], 200),
        ]);

        $credential = $this->kayit();
        $driver = new MailcowPasswordDriver;
        $session = $driver->open($credential, 'alanyoneticisi', 'API-ANAHTARI');

        $driver->changePassword($session, 'alanyoneticisi', 'yeni-parola');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'edit/domain-admin')) {
                return false;
            }

            return $request['items'] === ['alanyoneticisi']
                && $request['attr']['password'] === 'yeni-parola'
                && $request['attr']['password2'] === 'yeni-parola';
        });
    }

    #[Test]
    public function kurtarma_hesabi_acmaz_ama_tur_calisir(): void
    {
        Http::fake([
            '*/api/v1/get/status/version' => Http::response(['version' => '2024-01'], 200),
            '*/api/v1/edit/domain-admin' => Http::response([['type' => 'success']], 200),
        ]);

        $credential = $this->kayit();

        $rotation = app(PasswordRotator::class)->rotate($credential);

        /*
         * Doğrulanamayan servis "başarılı" sayılmaz. Kasa güncellenir çünkü
         * değişiklik yazıldı ve API anahtarı hâlâ elimizde; ama kanıtlanmamış
         * bir şeye kanıtlanmış demeyiz.
         */
        $this->assertSame(RotationStatus::Unverified, $rotation->status);
        $this->assertFalse($rotation->recovery_active, 'Kurtarma hesabı açılmamalı.');
        $this->assertNull($rotation->recovery_username);
        $this->assertNotSame('eski-parola', $credential->fresh()->password);
        $this->assertSame($rotation->attempted_password, $credential->fresh()->password);
    }
}
