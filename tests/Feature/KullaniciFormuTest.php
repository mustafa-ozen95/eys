<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kullanıcı formundaki GİRDİLER gerçekten çiziliyor mu.
 *
 * Etiket, ipucu ve "Rol zorunludur" hatası yerindeyken girdinin kendisi
 * çizilmeyebilir — kullanıcıdan seçmesi istenen şeyi seçeceği kutu ekranda
 * bulunmaz.
 *
 * Olası sebep bileşen etiketi içindeki `@disabled` / `@required` yönergeleri
 * (bkz. BladeBilesenEtiketiTest). Orası KALIBI kilitliyor; burası bu üç
 * kutunun varlığını.
 *
 * Etiketin değil GİRDİNİN varlığı sınanır: hata tam olarak "etiket var,
 * girdi yok" biçimindeydi ve etikete bakan bir sınama bunu göremezdi.
 */
final class KullaniciFormuTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function yeni_kullanici_ekraninda_rol_ve_parola_kutulari_var(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.create'))
            ->assertOk()
            ->assertSee('<select', false)
            ->assertSee('id="role"', false)
            ->assertSee('id="password"', false)
            ->assertSee('id="password_confirmation"', false)
            /* Ham bileşen etiketi tarayıcıya ULAŞMAMALI. */
            ->assertDontSee('<x-form.', false);
    }

    #[Test]
    public function duzenleme_ekraninda_rol_ve_parola_kutulari_var(): void
    {
        $hedef = User::factory()->create([
            'role_id' => Role::query()->where('is_admin', false)->value('id')
                ?? Role::query()->value('id'),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.edit', $hedef))
            ->assertOk()
            ->assertSee('id="role"', false)
            ->assertSee('id="password"', false)
            ->assertSee('id="password_confirmation"', false)
            ->assertDontSee('<x-form.', false);
    }

    /**
     * Kendi rolünü değiştiremeyen kullanıcıda kutu KAPALI gelir — ama gelir.
     *
     * Kapalılık `:disabled` ile veriliyor; yönergeyle verildiğinde etiket
     * bileşen olarak derlenmiyor ve kutu hiç çizilmiyordu.
     */
    #[Test]
    public function kendi_rolunu_degistiremeyen_kullanicida_kutu_kapali_gelir(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)
            ->get(route('users.edit', $admin))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="role"', $html);

        /* Öznitelik SIRASI önemli değil: kutunun kendisine bakılır. */
        $this->assertSame(
            1,
            preg_match('/<select[^>]*id="role"[^>]*>/', $html, $etiket),
            'Rol kutusu çizilmemiş.',
        );

        $this->assertStringContainsString('disabled', $etiket[0], 'Kendi rolü için kutu kapalı gelmeli.');
    }
}
