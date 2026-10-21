<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\User;
use App\Services\ErrorLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Hata kaydı.
 *
 * Kilitlenen şeyler: gürültü listeyi doldurmuyor, aynı hata tek satır
 * kalıyor, yığın izi parola taşımıyor ve sayfa yalnızca yöneticide.
 */
class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ayni_hata_tek_satir_kalir_sayac_artar(): void
    {
        $firlat = fn () => throw new RuntimeException('ayni hata');

        for ($i = 0; $i < 3; $i++) {
            try {
                $firlat();
            } catch (RuntimeException $e) {
                app(ErrorLogger::class)->record($e);
            }
        }

        $this->assertSame(1, ErrorLog::count(), 'Tek satır kalmalı.');
        $this->assertSame(3, ErrorLog::first()->occurrences);
    }

    #[Test]
    public function dogrulama_yetki_ve_404_gurultusu_kaydedilmez(): void
    {
        /*
         * Bunlar uygulamanın NORMAL çalışması. Listeye girseydi liste
         * kullanılmaz hâle gelir, asıl hata görünmezdi.
         */
        app(ErrorLogger::class)->record(new NotFoundHttpException('yok'));
        app(ErrorLogger::class)->record(new AuthorizationException('yetkisiz'));

        $this->assertSame(0, ErrorLog::count());
    }

    #[Test]
    public function yigin_izi_cagri_argumanlarini_tasimaz(): void
    {
        $gizli = 'COK-GIZLI-PAROLA';

        $baglan = function (string $parola): void {
            throw new RuntimeException('baglanti kurulamadi');
        };

        try {
            $baglan($gizli);
        } catch (RuntimeException $e) {
            app(ErrorLogger::class)->record($e);
        }

        /*
         * PHP'nin kendi izi çağrı argümanlarını taşıyabiliyor ve bu panelde
         * argümanlar parola oluyor. İz dosya/satır/metot ile sınırlı üretilir.
         */
        $this->assertStringNotContainsString($gizli, (string) ErrorLog::first()->trace);
    }

    #[Test]
    public function hata_sayfasi_yalnizca_yoneticide(): void
    {
        $this->actingAs(User::factory()->create())->get(route('errors.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('errors.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('errors.index'))->assertOk();
    }

    #[Test]
    public function oturumsuz_kullanici_hata_sayfasini_goremez(): void
    {
        $this->get(route('errors.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function cozulen_hata_tekrar_ederse_yeniden_acilir(): void
    {
        $admin = User::factory()->admin()->create();

        try {
            throw new RuntimeException('tekrar eden');
        } catch (RuntimeException $e) {
            app(ErrorLogger::class)->record($e);
        }

        $hata = ErrorLog::first();

        $this->actingAs($admin)->post(route('errors.resolve', $hata))->assertRedirect();
        $this->assertSame(0, ErrorLog::open()->count());

        /* "Çözüldü" geçmişe dairdir; hata devam ediyorsa çözülmemiştir. */
        ErrorLog::query()->whereKey($hata->id)->update(['last_seen_at' => now()->addMinute()]);

        $this->assertSame(1, ErrorLog::open()->count());
        $this->assertTrue(ErrorLog::first()->isReopened());
    }

    #[Test]
    public function hata_kaydinda_silme_ucu_yoktur(): void
    {
        /*
         * Silme olsaydı bir hatayı görmezden gelmenin en kolay yolu onu yok
         * etmek olurdu.
         */
        $rotalar = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

        $this->assertFalse(
            $rotalar->contains(fn (string $uri) => str_contains($uri, 'hatalar') && str_contains($uri, 'sil')),
        );
    }
}
