<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kullanıcının kendi hesabı.
 *
 * Kasanın kapısında duran bir panelde "kendi profilini düzenle" yetkisi,
 * kendini yönetici yapma yetkisi DEĞİLDİR. Bu testlerin asıl işi o sınırı
 * kilitlemek.
 */
class ProfilTest extends TestCase
{
    use RefreshDatabase;

    private function kullanici(array $ustune = []): User
    {
        return User::factory()->create([
            'name' => 'Deneme Kişi',
            'email' => 'kisi@ornek.test',
            'password' => Hash::make('parola-1234'),
            ...$ustune,
        ]);
    }

    #[Test]
    public function profil_oturumsuz_acilmaz(): void
    {
        $this->get(route('hesap.profil'))->assertRedirect(route('login'));
    }

    #[Test]
    public function kullanici_kendi_bilgilerini_degistirir(): void
    {
        $kullanici = $this->kullanici();

        $this->actingAs($kullanici)
            ->put(route('hesap.profil.kaydet'), ['name' => 'Yeni Ad', 'email' => 'yeni@ornek.test'])
            ->assertSessionHas('status');

        $kullanici->refresh();

        $this->assertSame('Yeni Ad', $kullanici->name);
        $this->assertSame('yeni@ornek.test', $kullanici->email);
    }

    #[Test]
    public function baskasinin_epostasi_alinamaz(): void
    {
        $this->kullanici(['email' => 'dolu@ornek.test']);

        $this->actingAs($this->kullanici(['email' => 'benim@ornek.test']))
            ->put(route('hesap.profil.kaydet'), ['name' => 'Ad', 'email' => 'dolu@ornek.test'])
            ->assertSessionHasErrors('email');
    }

    /**
     * ROL BURADAN DEĞİŞMEZ.
     *
     * Formda alanı yok; gönderilse bile yok sayılmalı. Aksi hâlde kasadaki
     * her kullanıcı kendini yönetici yapabilirdi.
     */
    #[Test]
    public function profilden_rol_degistirilemez(): void
    {
        $kullanici = $this->kullanici();
        $onceki = $kullanici->role_id;

        $this->actingAs($kullanici)->put(route('hesap.profil.kaydet'), [
            'name' => 'Ad',
            'email' => 'kisi@ornek.test',
            'role_id' => 1,
            'status' => 'active',
        ]);

        $this->assertSame($onceki, $kullanici->fresh()->role_id);
    }

    #[Test]
    public function parola_degistirmek_mevcut_parolayi_ister(): void
    {
        $kullanici = $this->kullanici();

        $this->actingAs($kullanici)->put(route('hesap.parola'), [
            'mevcut' => 'yanlis',
            'password' => 'yeni-parola-42',
            'password_confirmation' => 'yeni-parola-42',
        ])->assertSessionHasErrors('mevcut');

        $this->assertTrue(Hash::check('parola-1234', $kullanici->fresh()->password));
    }

    #[Test]
    public function dogru_mevcut_parolayla_degisir_ve_kayda_gecer(): void
    {
        $kullanici = $this->kullanici();

        $this->actingAs($kullanici)->put(route('hesap.parola'), [
            'mevcut' => 'parola-1234',
            'password' => 'yeni-parola-42',
            'password_confirmation' => 'yeni-parola-42',
        ])->assertSessionHas('status');

        $this->assertTrue(Hash::check('yeni-parola-42', $kullanici->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['summary' => 'Kendi parolasını değiştirdi']);
    }

    #[Test]
    public function kisa_parola_kabul_edilmez(): void
    {
        $kullanici = $this->kullanici();

        $this->actingAs($kullanici)->put(route('hesap.parola'), [
            'mevcut' => 'parola-1234',
            'password' => 'kisa1',
            'password_confirmation' => 'kisa1',
        ])->assertSessionHasErrors('password');
    }

    /** Menüde çıkışın yanında hesap bağlantısı da bulunur. */
    #[Test]
    public function ust_menude_hesap_baglantisi_bulunur(): void
    {
        /* Listeyi görebilen biri gerek; menü her ekranda aynı. */
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index'))
            ->assertOk()
            ->assertSee(route('hesap.profil'), escape: false);
    }
}
