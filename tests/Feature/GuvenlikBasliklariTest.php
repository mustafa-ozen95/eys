<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CihazArayuzu\ArayuzOturumu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tarayıcıya gönderilen güvenlik başlıkları.
 *
 * Bu bir parola kasası; başlıklar olmasa görünmez bir çerçeveye alınıp
 * kullanıcının tıklaması "parolayı göster" düğmesine yönlendirilebilirdi.
 *
 * İkinci sınama birincisi kadar önemli: cihaz arayüzü vekili panelin kendisi
 * DEĞİL ve panel onu bilerek çerçeveye alıyor. Oraya da çerçeve kilidi
 * koysaydık bütün cihaz arayüzleri kapanırdı — vekil ayrı portta duruyor ve
 * ayrı port ayrı kökendir.
 */
class GuvenlikBasliklariTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function panel_sayfasi_cerceveye_alinamaz(): void
    {
        $yanit = $this->get(route('login'));

        $yanit->assertOk();
        $yanit->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $csp = (string) $yanit->headers->get('Content-Security-Policy');

        /* Satır içi script ve style YOK; politikanın anlamı buradan geliyor. */
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $yanit->assertHeader('X-Content-Type-Options', 'nosniff');
        $yanit->assertHeader('Referrer-Policy', 'same-origin');
    }

    #[Test]
    public function cihaz_arayuzu_uclarina_cerceve_kilidi_konmaz(): void
    {
        /*
         * Jeton yok, dolayısıyla yanıt bir hata olacak — önemi yok: sınanan
         * şey ara katmanın ne EKLEDİĞİ, uç noktanın ne döndürdüğü değil.
         */
        $yanit = $this->get(ArayuzOturumu::onek().'/'.'00000000-0000-4000-8000-000000000000'.'/');

        $yanit->assertHeaderMissing('X-Frame-Options');
        $yanit->assertHeaderMissing('Content-Security-Policy');

        /* Öteki ikisi yine gider. */
        $yanit->assertHeader('X-Content-Type-Options', 'nosniff');
        $yanit->assertHeader('Referrer-Policy', 'same-origin');
    }

    /**
     * OTURUMSUZ İSTEĞİN YÖNLENDİRMESİ DE BAŞLIK TAŞIR.
     *
     * Ara katman önce "web" grubuna eklenmişti ve en içte kalıyordu: kimlik
     * doğrulaması düşünce fırlatılan istisna oradan yukarı geçiyor, yanıtı
     * istisna işleyicisi kuruyor ve o yanıt bir daha ara katmandan geçmiyordu.
     * Sonuç: giriş yönlendirmesi ve bütün hata sayfaları çıplaktı. Artık genel
     * yığında.
     */
    #[Test]
    public function oturumsuz_istegin_yonlendirmesi_de_baslik_tasir(): void
    {
        $yanit = $this->get(route('credentials.index'));

        $yanit->assertRedirect();
        $yanit->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $yanit->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
