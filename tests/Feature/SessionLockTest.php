<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ekran kilidi: hareketsiz sekme oturumu kapatır.
 *
 * Süre sayfaya SUNUCUDAN gelir; kilit (session-lock.js) başka bir sayı
 * tutmaz. Etiket yalnızca oturum açıkken basılır: giriş ekranında kilit
 * çalışmaz.
 */
class SessionLockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oturum_acikken_sure_ve_adresler_basilir(): void
    {
        config(['session.lifetime' => 60]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index'))
            ->assertOk()
            ->assertSee('<meta name="session-lifetime" content="60">', false)
            ->assertSee('<meta name="session-logout" content="'.route('logout').'">', false)
            ->assertSee('<meta name="session-login" content="'.route('login').'">', false);
    }

    /** Kullanıcı menüsünde merkeze (KYM) bağlantı — yalnızca merkez açıkken. */
    #[Test]
    public function kullanici_menusunde_merkez_baglantisi(): void
    {
        $kullanici = User::factory()->admin()->create();

        config(['identity.acik' => true, 'identity.url' => 'https://merkez.ornek.test', 'identity.name' => 'Kimlik Yönetim Merkezi']);
        $this->actingAs($kullanici)->get(route('credentials.index'))
            ->assertSee('href="https://merkez.ornek.test" class="user-menu__item" target="_blank" rel="noopener">Hızlı Erişim</a>', false);

        config(['identity.acik' => false]);
        $this->actingAs($kullanici)->get(route('credentials.index'))->assertDontSee('merkez.ornek.test', false);
    }

    #[Test]
    public function giris_ekraninda_kilit_yok(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('session-lifetime', false);
    }
}
