<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Oturum açık sayfalar tarayıcı diskine yazılmaz.
 *
 * Laravel kendiliğinden `no-cache, private` yazıyor ve bu YETMİYOR:
 * `no-cache` "kullanmadan önce sor" der, "saklama" demez. Burası bir parola
 * kasası — sayfada kullanıcı adları ve "göster"e basıldığında parolanın
 * kendisi duruyor; ortak bir makinede çıkıştan sonra o dosya diskte kalır.
 *
 * İkinci etkisi: `no-cache` geri/ileri önbelleğini kapatmıyor. Kullanıcı
 * geri gidip geldiğinde tarayıcı sayfayı sunucuya sormadan bellekten
 * çiziyordu; panele eklenen yeni bir düğme o sayfada hiç görünmüyordu.
 */
class OturumluSayfaDiskeYazilmazTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oturumlu_sayfa_no_store_tasir(): void
    {
        $yanit = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index'));

        $yanit->assertOk();
        $this->assertStringContainsString('no-store', (string) $yanit->headers->get('Cache-Control'));
    }

    /** Giriş sayfasının saklanacak bir şeyi yok; kural oraya karışmaz. */
    #[Test]
    public function giris_sayfasina_karismaz(): void
    {
        $yanit = $this->get(route('login'));

        $this->assertStringNotContainsString('no-store', (string) $yanit->headers->get('Cache-Control'));
    }
}
