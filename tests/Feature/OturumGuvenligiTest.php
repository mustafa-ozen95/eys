<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Oturum parolaya bağlıdır; merkezle giriş uzun ömürlü çerez vermez;
 * kimlik eşleştirmesine giren e-posta kendi kendine yazılamaz.
 *
 * (1) Auth::logoutOtherDevices ancak AuthenticateSession ara katmanı
 * kayıtlıyken işler: parola değişince (yönetici sıfırlamasında da) eski
 * oturumlar kapanır; (2) merkez dönüşü hatırlama çerezi vermez; (3) kullanıcı
 * kendi e-postasını değiştirerek henüz bağlanmamış bir çalışanın merkez
 * kimliğini kendi hesabına çekemez.
 */
class OturumGuvenligiTest extends TestCase
{
    use RefreshDatabase;

    /** Oturumda duran parola özeti, "eski tarayıcı" gibi davranır. */
    private function eskiTarayici(User $kullanici, string $ozet)
    {
        return $this->actingAs($kullanici)->withSession(['password_hash_web' => $ozet]);
    }

    #[Test]
    public function parola_degisince_eski_oturum_duser(): void
    {
        $kullanici = User::factory()->create();
        $eskiOzet = $kullanici->getAuthPassword();

        /* Başka bir tarayıcıda açık oturum: özet kullanıcınınkiyle aynı, geçerli. */
        $this->eskiTarayici($kullanici, $eskiOzet)->get(route('hesap.profil'))->assertOk();

        $kullanici->update(['password' => 'Yeni-parola-123!']);

        $this->eskiTarayici($kullanici->fresh(), $eskiOzet)->get(route('hesap.profil'))->assertRedirect(route('login'));
    }

    #[Test]
    public function kendi_parolasini_degistiren_kendi_oturumunda_kalir(): void
    {
        $kullanici = User::factory()->create();

        $this->actingAs($kullanici)
            ->put(route('hesap.parola'), [
                'mevcut' => 'gecerli-parola-1!',
                'password' => 'Yeni-parola-123!',
                'password_confirmation' => 'Yeni-parola-123!',
            ])->assertSessionHasNoErrors();

        $this->get(route('hesap.profil'))->assertOk();
    }

    #[Test]
    public function yonetici_sifirlamasi_hedefin_acik_oturumunu_dusurur(): void
    {
        $admin = User::factory()->admin()->create();
        $hedef = User::factory()->create();
        $eskiOzet = $hedef->getAuthPassword();
        $rol = Role::query()->where('slug', 'yetkisiz')->first();

        $this->actingAs($admin)->put(route('users.update', $hedef), [
            'name' => $hedef->name, 'email' => $hedef->email, 'role' => $rol->uuid, 'status' => 'active',
            'password' => 'Sifirlandi-123!x', 'password_confirmation' => 'Sifirlandi-123!x',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Hash::check('gecerli-parola-1!', $hedef->fresh()->password));
        $this->eskiTarayici($hedef->fresh(), $eskiOzet)->get(route('hesap.profil'))->assertRedirect(route('login'));

        /* Yönetici kendi oturumunda kalır; kendi parolasını sıfırlasa da. */
        $this->actingAs($admin)->get(route('hesap.profil'))->assertOk();
        $this->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role->uuid, 'status' => 'active',
            'password' => 'Yonetici-yeni-123!', 'password_confirmation' => 'Yonetici-yeni-123!',
        ])->assertSessionHasNoErrors();
        $this->get(route('hesap.profil'))->assertOk();
    }

    private function merkezAc(): void
    {
        config([
            'kimlik.acik' => true, 'kimlik.yapilandirildi' => true, 'kimlik.adres' => 'https://merkez.test',
            'kimlik.istemci' => 'istemci-1', 'kimlik.sir' => 'sir-1',
            'kimlik.donus' => 'https://kasa.test/giris/merkez/geri', 'kimlik.ad' => 'Merkez',
        ]);
    }

    #[Test]
    public function merkez_girisi_hatirlama_cerezi_vermez(): void
    {
        $this->merkezAc();
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'j']),
            'merkez.test/oauth/kullanici' => Http::response(['sub' => 'm-1', 'name' => 'Ali Demir', 'email' => 'ali@example.test', 'email_verified' => true]),
        ]);
        $kullanici = User::factory()->create(['email' => 'ali@example.test']);

        $yanit = $this->withSession(['kimlik_durum' => 'd', 'kimlik_dogrulayici' => 'v'])
            ->get(route('kimlik.donus', ['code' => 'k', 'state' => 'd']));

        $yanit->assertRedirect(route('credentials.index'));
        $this->assertAuthenticatedAs($kullanici);

        foreach ($yanit->headers->getCookies() as $cerez) {
            $this->assertStringStartsNotWith('remember_web', $cerez->getName(), 'Uzun ömürlü hatırlama çerezi verilmemeli.');
        }
    }

    #[Test]
    public function merkez_aciken_kullanici_kendi_epostasini_degistiremez(): void
    {
        $this->merkezAc();
        $kullanici = User::factory()->create(['email' => 'ben@example.test', 'name' => 'Ben']);

        $this->actingAs($kullanici)
            ->put(route('hesap.profil.kaydet'), ['name' => 'Yeni Ad', 'email' => 'yeni-calisan@example.test'])
            ->assertSessionHasNoErrors();

        $kullanici->refresh();
        $this->assertSame('Yeni Ad', $kullanici->name, 'Ad yine düzenlenir.');
        $this->assertSame('ben@example.test', $kullanici->email);

        $this->get(route('hesap.profil'))->assertOk()->assertSee('yönetici değiştirir');
    }

    #[Test]
    public function merkez_kapaliyken_eposta_eskisi_gibi_duzenlenir(): void
    {
        config(['kimlik.acik' => false]);
        $kullanici = User::factory()->create(['email' => 'ben@example.test']);

        $this->actingAs($kullanici)
            ->put(route('hesap.profil.kaydet'), ['name' => 'Ben', 'email' => 'yeni@example.test'])
            ->assertSessionHasNoErrors();

        $this->assertSame('yeni@example.test', $kullanici->fresh()->email);
    }
}
