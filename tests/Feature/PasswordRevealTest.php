<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Parola gösterme ucu.
 *
 * "Görüntüle" yetkisi parolayı KAPSAMAZ ve her gösterim kayda geçer. İkisi de
 * ayrı ayrı kilitlenir: biri kaldırılırsa test düşer.
 */
class PasswordRevealTest extends TestCase
{
    use RefreshDatabase;

    private function kullanici(bool $canReveal): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, $canReveal ? ['view', 'field.password'] : ['view']);

        $credential = Credential::factory()->for($group, 'group')->create([
            'password' => 'ACIK-PAROLA-7',
        ]);

        return [$user, $credential];
    }

    #[Test]
    public function gorme_yetkisi_parolayi_kapsamaz(): void
    {
        [$user, $credential] = $this->kullanici(canReveal: false);

        $this->actingAs($user)
            ->post(route('ajax.credentials.password', $credential))
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'revealed']);
    }

    #[Test]
    public function parola_yetkisi_olan_gorur_ve_gosterim_kayda_gecer(): void
    {
        [$user, $credential] = $this->kullanici(canReveal: true);

        $response = $this->actingAs($user)
            ->post(route('ajax.credentials.password', $credential));

        $response->assertOk()
            ->assertJsonPath('password', 'ACIK-PAROLA-7')
            ->assertJsonPath('username', $credential->username);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'revealed',
            'subject_id' => $credential->id,
        ]);
    }

    #[Test]
    public function parola_ucu_get_ile_cagrilamaz(): void
    {
        [$user, $credential] = $this->kullanici(canReveal: true);

        $this->actingAs($user)
            ->get('/access/'.$credential->uuid.'/password')
            ->assertMethodNotAllowed();
    }

    #[Test]
    public function parolasi_olmayan_kayit_404_verir(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $credential = Credential::factory()->for($group, 'group')->withoutPassword()->create();

        $this->actingAs($user)
            ->post(route('ajax.credentials.password', $credential))
            ->assertNotFound();
    }

    #[Test]
    public function parola_veritabaninda_duz_metin_durmaz(): void
    {
        $credential = Credential::factory()->create(['password' => 'DUZ-METIN-99']);

        $ham = DB::table('credentials')
            ->where('id', $credential->id)
            ->value('password');

        $this->assertNotSame('DUZ-METIN-99', $ham);
        $this->assertStringNotContainsString('DUZ-METIN-99', (string) $ham);
        $this->assertSame('DUZ-METIN-99', $credential->fresh()->password);
    }

    #[Test]
    public function parola_json_ciktisinda_gizlidir(): void
    {
        $credential = Credential::factory()->create(['password' => 'GIZLI-1']);

        $this->assertArrayNotHasKey('password', $credential->toArray());
        $this->assertArrayNotHasKey('notes', $credential->toArray());
    }

    /**
     * Kullanıcıya bir grupta yetki verir.
     *
     * Testler bunu tek yerden çağırır: izin satırının nasıl yazıldığı
     * değişirse tek dosyada değişsin.
     *
     * @param  list<string>  $actions
     */
    private function yetkilendir(User $user, CredentialGroup $group, array $actions): void
    {
        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $user->unsetRelation('permissions');
    }
}
