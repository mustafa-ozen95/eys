<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SON GİRİŞ HANGİ KAPIDAN GİRİLİRSE GİRİLSİN YAZILIR.
 *
 * Alan yalnızca panelin kendi parola girişinde yazılsaydı merkezle (KYM)
 * giriş yapanlarda kullanıcılar ekranında "Son giriş —" kalırdı. Kayıt,
 * EKRANIN BAKTIĞI YERE yazılır.
 *
 * Kural `Login` olayına bağlı, yani yeni bir giriş yolu eklendiğinde de
 * çalışır.
 */
class LastLoginWrittenTest extends TestCase
{
    use RefreshDatabase;

    /** Parola ile giriş. */
    public function test_parola_ile_giriste_yazilir(): void
    {
        $user = User::factory()->create(['password' => bcrypt('cok-gizli-parola')]);

        $this->assertNull($user->last_login_at);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'cok-gizli-parola',
        ]);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /**
     * MERKEZLE GİRİŞTE DE yazılır.
     *
     * Merkez akışının tamamı burada kurulmuyor; kritik olan, o akışın
     * kullandığı `Auth::login`in alanı güncellemesi.
     */
    public function test_merkezle_giriste_de_yazilir(): void
    {
        $user = User::factory()->create();

        Auth::login($user);

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /** Giriş kaydı, kaydın kendisini "değişti" saymaz: denetim kaydı şişmesin. */
    public function test_giris_kullanici_guncellendi_kaydi_dusurmez(): void
    {
        $user = User::factory()->create();

        $once = DB::table('activity_logs')->count();

        Auth::login($user);

        $this->assertSame($once, DB::table('activity_logs')->count());
    }
}
