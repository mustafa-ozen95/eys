<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Girişte hesap kilidi — ayardan gelir, sıfır "kilit yok" demektir.
 *
 * Sayı okunduğu yerde sabit bir varsayılan değil, ayardan gelir; kurulum
 * isterse kilidi sıfırla kapatabilir.
 *
 * İKİNCİ SINAMA BİRİNCİSİ KADAR ÖNEMLİ: sıfırı "sınırsız" sanıp doğrudan
 * `tooManyAttempts($key, 0)` çağırmak kilidi KALDIRMAZ, İLK yanlış paroladan
 * sonra kilitler — kapatmak isterken en sert hâline getirir.
 */
class GirisKilidiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('kisi@ornek.test|127.0.0.1');
    }

    private function kullanici(): User
    {
        return User::factory()->create([
            'email' => 'kisi@ornek.test',
            'password' => Hash::make('dogru-parola-1234'),
            'status' => UserStatus::Active,
        ]);
    }

    /** @return TestResponse */
    private function dene(string $parola)
    {
        return $this->post(route('login'), [
            'email' => 'kisi@ornek.test',
            'password' => $parola,
        ]);
    }

    #[Test]
    public function sinir_sifirken_art_arda_yanlis_parola_kilitlemez(): void
    {
        config(['auth.max_attempts' => 0]);

        $this->kullanici();

        for ($i = 0; $i < 12; $i++) {
            $this->dene('yanlis')->assertSessionHasErrors('email');
        }

        /* Kilit olsaydı mesaj "Çok fazla deneme" olurdu ve doğru parola da girmezdi. */
        $this->dene('dogru-parola-1234')->assertRedirect();
        $this->assertAuthenticated();
    }

    #[Test]
    public function sinir_verilince_kilit_calisir(): void
    {
        config(['auth.max_attempts' => 3]);

        $this->kullanici();

        for ($i = 0; $i < 3; $i++) {
            $this->dene('yanlis');
        }

        /* Sayaç dolduktan sonra DOĞRU parola bile geçmez. */
        $this->dene('dogru-parola-1234')->assertInvalid(['email' => 'Çok fazla deneme']);

        $this->assertGuest();
    }
}
