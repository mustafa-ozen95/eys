<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uplink alanı: adresin yanında görünür, aranır,
 * adresle aynı izne (field.host) bağlıdır.
 */
final class UplinkTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $actions */
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

    private function kayit(CredentialGroup $group, string $ad, ?string $uplink): Credential
    {
        return Credential::factory()->create([
            'credential_group_id' => $group->getKey(),
            'name' => $ad,
            'host' => '192.0.2.35',
            'uplink' => $uplink,
        ]);
    }

    #[Test]
    public function kayit_formu_uplinki_yazar_ve_gecersiz_adresi_reddeder(): void
    {
        $user = User::factory()->admin()->create();
        $group = CredentialGroup::factory()->create();
        $veri = [
            'group' => $group->uuid, 'name' => 'Kabin-1 (admin)', 'access_type' => 'ssh', 'operating_system' => 'linux',
            'host' => '192.0.2.35', 'username' => 'admin', 'password' => 'ilk-parola', 'is_active' => '1', 'position' => 0,
        ];

        $this->actingAs($user)->post(route('credentials.store'), [...$veri, 'uplink' => 'bu bir adres değil'])
            ->assertSessionHasErrors('uplink');

        $this->actingAs($user)->post(route('credentials.store'), [...$veri, 'uplink' => '203.0.113.110'])
            ->assertSessionHasNoErrors();

        $this->assertSame('203.0.113.110', Credential::query()->where('name', 'Kabin-1 (admin)')->sole()->uplink);
    }

    #[Test]
    public function uplinkle_aranir_ve_sutun_yalnizca_doluysa_cizilir(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.host', 'field.password']);
        $this->kayit($group, 'Kabin Bir', '203.0.113.110');
        $this->kayit($group, 'Kabin Iki', '203.0.113.46');

        $this->actingAs($user)->get(route('credentials.index', ['grup' => $group->uuid, 'q' => '203.0.113.110']))
            ->assertOk()->assertSee('Kabin Bir')->assertDontSee('Kabin Iki')
            ->assertSee('credential-row__uplink', false);

        /* Yetki çözümü istek içinde önbelleğe alınıyor: ikinci grup için ayrı kullanıcı. */
        $baska = User::factory()->create();
        $bos = CredentialGroup::factory()->create();
        $this->yetkilendir($baska, $bos, ['view', 'field.host', 'field.password']);
        $this->kayit($bos, 'Uplinksiz', null);

        $this->actingAs($baska)->get(route('credentials.index', ['grup' => $bos->uuid]))
            ->assertOk()->assertSee('Uplinksiz')->assertDontSee('credential-row__uplink', false);
    }

    #[Test]
    public function adres_izni_olmayan_uplinki_goremez_ve_onunla_arayamaz(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view']);
        $kayit = $this->kayit($group, 'Kabin Gizli', '203.0.113.199');

        $this->actingAs($user)->get(route('credentials.index', ['grup' => $group->uuid, 'kayit' => $kayit->uuid]))
            ->assertOk()->assertDontSee('203.0.113.199');

        $this->actingAs($user)->get(route('credentials.index', ['grup' => $group->uuid, 'q' => '203.0.113.199']))
            ->assertOk()->assertDontSee('Kabin Gizli');
    }
}
