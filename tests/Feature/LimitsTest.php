<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * İstek sınırları.
 *
 * Sayılar ayara taşındı (bkz. config/limits.php) ki görsel test koşusu
 * onları yükseltebilsin. Taşımanın riski, üretim değerinin farkında olmadan
 * gevşemesidir: bu test varsayılanları ve uçlara gerçekten uygulandığını
 * kilitler.
 */
class LimitsTest extends TestCase
{
    #[Test]
    public function uretim_varsayilanlari_degismedi(): void
    {
        /*
         * Tek kayıt güncelleme ve onarma SINIRSIZDIR (0).
         *
         * Dakikada beşti ve kullanımda engel oluyordu: birkaç kaydı arka arkaya
         * güncellemek ya da bir turu yeniden denemek 429 ile duruyordu. Uç
         * zaten yetki, gerekçe ve cihaz başına tekillik kilidiyle korunuyor;
         * sayı saymanın eklediği bir güvence yoktu.
         */
        $this->assertSame(0, config('limits.rotate'));
        $this->assertSame(0, config('limits.rotate_repair'));

        $this->assertSame(3, config('limits.rotate_bulk'), 'Toplu güncelleme tek istekte onlarca makineye dokunur.');
        $this->assertSame(10, config('limits.portal'));
        $this->assertSame(20, config('limits.reveal'));
        /*
         * Giriş sınırı VARSAYILAN OLARAK KAPALIDIR (0 = sınırsız).
         *
         * Kasa için bir sınır önerilir ama varsayılanı açık bırakmak, aynı
         * ağdan çalışan birkaç kişinin birbirini kilitlemesine yol açıyordu
         * ve kilidi açmanın panelden bir yolu yok. Koruma ters vekil ya da
         * güvenlik duvarı katmanında, oraya ait bir karar olarak verilir;
         * LOGIN_THROTTLE doldurulursa aşağıdaki sınır yeniden devreye girer.
         */
        $this->assertSame(0, config('auth.login_throttle'));
    }

    /**
     * Sınır GERÇEKTEN uca bağlı mı?
     *
     * Ayarı okuyup middleware'e vermeyi unutmak sessiz bir açık olurdu:
     * dosyada sayı durur, uçta sınır olmazdı.
     */
    #[Test]
    public function sinirlar_uclara_uygulanir(): void
    {
        $beklenen = [
            'erisim/toplu-dondur' => 'throttle:3,1',
            'erisim/{credential}/oturum' => 'throttle:10,1',
            'erisim/{credential}/parola' => 'throttle:20,1',
        ];

        foreach ($beklenen as $uri => $sinir) {
            $rota = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r): bool => $r->uri() === $uri && in_array('POST', $r->methods(), true));

            $this->assertNotNull($rota, "Rota bulunamadı: {$uri}");
            $this->assertContains($sinir, $rota->gatherMiddleware(), "Sınır uygulanmamış: {$uri}");
        }
    }

    /**
     * SIFIR OLAN SINIR UCA HİÇ TAKILMAZ.
     *
     * "throttle:0,1" yazmak sınırı kaldırmaz, dakikada SIFIR isteğe indirir ve
     * ucu tamamen kapatırdı. Parola güncelleme sınırı kullanımda engel oluyordu
     * (dakikada beş; birkaç kaydı arka arkaya güncellemek 429 ile duruyordu)
     * ve sıfıra çekildi; o zaman ara katmanın hiç eklenmemesi gerekiyor.
     */
    #[Test]
    public function sifir_sinir_hic_takilmaz(): void
    {
        $this->assertSame(0, (int) config('limits.rotate'));

        $rota = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->uri() === 'erisim/{credential}/dondur'
                && in_array('POST', $r->methods(), true));

        $this->assertNotNull($rota);

        foreach ($rota->gatherMiddleware() as $katman) {
            $this->assertStringNotContainsString('throttle', (string) $katman);
        }
    }

    /**
     * Giriş sınırı KAPALIYKEN ara katman hiç takılmaz.
     *
     * `throttle:0,1` yazmak sınırı kapatmaz — dakikada SIFIR isteğe izin
     * verir, yani girişi tamamen kilitler. Sıfır, ara katmanın hiç
     * eklenmemesi demek olmalı.
     */
    #[Test]
    public function giris_siniri_sifirken_ara_katman_eklenmez(): void
    {
        $rota = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r): bool => $r->uri() === 'giris' && in_array('POST', $r->methods(), true));

        $this->assertNotNull($rota, 'Giriş rotası bulunamadı.');

        $sinirlar = array_filter(
            $rota->gatherMiddleware(),
            fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'),
        );

        $this->assertNotContains('throttle:0,1', $sinirlar, 'Sıfır sınır girişi tamamen kilitlerdi.');
    }

    /**
     * Sınır VERİLDİĞİNDE uca gerçekten bağlanır.
     *
     * Kapalı varsayılan, sınırın hiç çalışmadığını gizleyebilirdi.
     */
    #[Test]
    public function giris_siniri_verildiginde_uygulanir(): void
    {
        /*
         * Rotalar uygulama açılırken kuruluyor; sınırı sonradan ayara yazmak
         * geç kalır. Bu yüzden ortam değişkeni verilip uygulama YENİDEN
         * kurulur — ayarın uca gerçekten bağlandığı ancak böyle görülür.
         */
        putenv('LOGIN_THROTTLE=7');
        $_ENV['LOGIN_THROTTLE'] = '7';
        $_SERVER['LOGIN_THROTTLE'] = '7';

        $this->refreshApplication();

        try {
            $rota = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r): bool => $r->uri() === 'giris' && in_array('POST', $r->methods(), true));

            $this->assertNotNull($rota, 'Giriş rotası bulunamadı.');
            $this->assertContains('throttle:7,1', $rota->gatherMiddleware());
        } finally {
            putenv('LOGIN_THROTTLE');
            unset($_ENV['LOGIN_THROTTLE'], $_SERVER['LOGIN_THROTTLE']);
        }
    }
}
