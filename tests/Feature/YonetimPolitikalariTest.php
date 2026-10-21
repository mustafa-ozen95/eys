<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Yönetici-dışı kimse kullanıcı ya da rol silemez.
 *
 * Policy yalnızca "yöneticinin de yapamayacakları"nı yazıp "yönetici mi"
 * sorusunu Gate::before'a bırakamaz: o kanca yönetici olmayanda null döner ve
 * karar policy'ye düşer. Yetkisiz (rolü hiçbir şey vermeyen) bir kullanıcı
 * başkasını, yöneticiyi de, silememeli.
 */
class YonetimPolitikalariTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function yetkisiz_kullanici_baskasini_silemez(): void
    {
        $admin = User::factory()->admin()->create();
        $teknik = User::factory()->teknik()->create();
        $kimse = User::factory()->create();

        $this->actingAs($kimse)->delete(route('users.destroy', $admin))->assertForbidden();
        $this->actingAs($kimse)->delete(route('users.destroy', $teknik))->assertForbidden();

        $this->assertModelExists($admin);
        $this->assertModelExists($teknik);
    }

    #[Test]
    public function yetkili_ama_yonetici_olmayan_rol_de_kullanici_silemez(): void
    {
        $network = User::factory()->network()->create();
        $hedef = User::factory()->create();

        $this->actingAs($network)->delete(route('users.destroy', $hedef))->assertForbidden();
        $this->assertModelExists($hedef);
    }

    #[Test]
    public function yetkisiz_kullanici_rol_silemez(): void
    {
        $rol = Role::query()->create(['slug' => 'ozel', 'label' => 'Özel', 'is_admin' => false, 'template' => []]);
        $kimse = User::factory()->create();

        $this->actingAs($kimse)->delete(route('roles.destroy', $rol))->assertForbidden();
        $this->assertModelExists($rol);
    }

    #[Test]
    public function yonetici_baskasini_ve_kullanicisiz_ozel_rolu_silebilir(): void
    {
        $admin = User::factory()->admin()->create();
        $hedef = User::factory()->create();
        $rol = Role::query()->create(['slug' => 'ozel', 'label' => 'Özel', 'is_admin' => false, 'template' => []]);

        $this->actingAs($admin)->delete(route('users.destroy', $hedef))->assertRedirect();
        $this->actingAs($admin)->delete(route('roles.destroy', $rol))->assertRedirect();

        $this->assertModelMissing($hedef);
        $this->assertModelMissing($rol);
    }
}
