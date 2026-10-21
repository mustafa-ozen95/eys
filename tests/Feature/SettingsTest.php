<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\Setting;
use App\Models\User;
use App\Services\Rotation\DriverRegistry;
use App\Services\Rotation\PasswordGenerator;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ayarlar.
 *
 * Kilitlenen şey: ekrandaki kutular GERÇEKTEN bir şey değiştiriyor. Hiçbir
 * şey yapmayan bir ayar kutusu, olmayan bir denetimi varmış gibi gösterir.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ayarlar_yalnizca_yoneticide(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('settings.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('settings.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('settings.index'))->assertOk();
    }

    #[Test]
    public function satiri_olmayan_ayar_varsayilaninda_kalir(): void
    {
        /* Bir ayarı sıfırlamak satırı silmektir; config her zaman geçerli taban. */
        $this->assertSame(0, Setting::count());
        $this->assertSame(config('rotation.password_length'), app(SettingsRepository::class)->current('rotation.password_length'));
    }

    #[Test]
    public function parola_uzunlugu_ayari_gercekten_uretimi_degistirir(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('settings.update'), [
            'group' => 'rotation',
            'rotation__enabled' => '1',
            'rotation__password_length' => 40,
            'rotation__password_alphabet' => config('rotation.password_alphabet'),
            'rotation__ssh_timeout' => 15,
            'rotation__mailcow_path' => '/opt/mailcow-dockerized',
        ])->assertSessionHasNoErrors();

        /* Yeni istekte bindirme uygulanmalı. */
        app(SettingsRepository::class)->flush();
        app(SettingsRepository::class)->apply();

        $this->assertSame(40, strlen(app(PasswordGenerator::class)->generate()));
    }

    #[Test]
    public function dondurme_kapatilinca_hicbir_kayit_dondurulemez(): void
    {
        $admin = User::factory()->admin()->create();

        $credential = Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.9',
            'username' => 'root',
            'password' => 'p',
            'rotation_enabled' => true,
        ]);

        /* Açıkken engel yok. */
        $this->assertNull(app(DriverRegistry::class)->blockedReason($credential));

        $this->actingAs($admin)->put(route('settings.update'), [
            'group' => 'rotation',
            'rotation__password_length' => 24,
            'rotation__password_alphabet' => config('rotation.password_alphabet'),
            'rotation__ssh_timeout' => 15,
            'rotation__mailcow_path' => '/opt/mailcow-dockerized',
            // rotation__enabled gönderilmiyor: kutu kapatıldı
        ])->assertSessionHasNoErrors();

        app(SettingsRepository::class)->flush();
        app(SettingsRepository::class)->apply();

        $this->assertStringContainsString(
            'panel ayarlarından kapatılmış',
            (string) app(DriverRegistry::class)->blockedReason($credential),
        );
    }

    #[Test]
    public function gecersiz_deger_reddedilir(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('settings.update'), [
            'group' => 'rotation',
            'rotation__password_length' => 4,
            'rotation__password_alphabet' => 'kisa',
            'rotation__ssh_timeout' => 9999,
            'rotation__mailcow_path' => 'gecersiz; rm -rf /',
        ])->assertSessionHasErrors([
            'rotation__password_length',
            'rotation__password_alphabet',
            'rotation__ssh_timeout',
            'rotation__mailcow_path',
        ]);

        $this->assertSame(0, Setting::count());
    }

    #[Test]
    public function yalnizca_degisenler_kayda_gecer_eski_ve_yeni_degerle(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('settings.update'), [
            'group' => 'security',
            'session__lifetime' => config('session.lifetime'),
            'auth__max_attempts' => 9,
        ])->assertSessionHasNoErrors();

        $log = ActivityLog::query()->where('summary', 'like', 'Ayar değişti%')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('auth.max_attempts', $log->summary);
        $this->assertStringContainsString('9', $log->summary);

        /* Değişmeyen alan mesajda GEÇMEMELİ. */
        $this->assertStringNotContainsString('session.lifetime', $log->summary);
    }

    #[Test]
    public function katalogda_olmayan_anahtar_yazilmaz(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('settings.update'), [
            'group' => 'security',
            'session__lifetime' => 120,
            'auth__max_attempts' => 5,
            'app__key' => 'ele-gecirme-denemesi',
        ]);

        /* Forma elle eklenen alan katalogda yok; hiç okunmaz. */
        $this->assertSame(0, Setting::query()->where('key', 'app.key')->count());
    }

    #[Test]
    public function roller_ayarlarin_bir_sekmesidir(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('settings.index'))->assertOk()->assertSee('Roller');
        $this->actingAs($admin)->get(route('roles.index'))->assertOk()->assertSee('Parola güncelleme');
    }
}
