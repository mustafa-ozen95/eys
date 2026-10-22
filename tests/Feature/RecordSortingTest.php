<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\SubGroup;
use App\Models\User;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAYIT SIRASI SÜRÜKLE-BIRAKLA.
 *
 * Sıra formdaki sayı değil artık; listede sürüklenir. Kurallar:
 *   - yalnızca yönetici,
 *   - kayıt kendi bölümünün (grup + alt grup) içinde kalır: alt gruptaki
 *     kayıt ana gruba sürüklenemez,
 *   - alt grup bölümleri de ana grubun içinde sürüklenir.
 */
class RecordSortingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CredentialGroup, 1: SubGroup, 2: list<Credential>, 3: Credential} */
    private function sahne(): array
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $alt = SubGroup::factory()->for($grup, 'group')->create(['name' => 'Bolum 1', 'position' => 1]);

        $bolumde = collect(['A', 'B', 'C', 'D'])->map(fn (string $ad, int $i) => Credential::factory()->create([
            'credential_group_id' => $grup->getKey(), 'alt_grup_id' => $alt->getKey(),
            'name' => $ad, 'position' => ($i + 1) * 10,
        ]))->all();

        $anaGrupta = Credential::factory()->create([
            'credential_group_id' => $grup->getKey(), 'alt_grup_id' => null, 'name' => 'Ana', 'position' => 10,
        ]);

        return [$grup, $alt, $bolumde, $anaGrupta];
    }

    /** @param  list<Credential>  $kayitlar */
    private function dizilim(SubGroup $alt): array
    {
        return Credential::query()->where('alt_grup_id', $alt->getKey())->ordered()->pluck('name')->all();
    }

    #[Test]
    public function yonetici_bolum_icinde_siralar(): void
    {
        [, $alt, [$a, $b, $c, $d]] = $this->sahne();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.reorder'), ['sira' => [$d->uuid, $c->uuid, $b->uuid, $a->uuid]])
            ->assertRedirect();

        $this->assertSame(['D', 'C', 'B', 'A'], $this->dizilim($alt));
    }

    /**
     * Süzgeç ya da kısıt bazı satırları gizlerse gelen liste eksiktir:
     * gizli satırlar yerinde kalmalı.
     */
    #[Test]
    public function eksik_liste_gizli_satirlarin_yerini_bozmaz(): void
    {
        [, $alt, [$a, $b, $c, $d]] = $this->sahne();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.reorder'), ['sira' => [$d->uuid, $b->uuid]]);

        /* A ve C yerinde; B ile D'nin yuvaları (2 ve 4) yeni sırayla D, B. */
        $this->assertSame(['A', 'D', 'C', 'B'], $this->dizilim($alt));
    }

    #[Test]
    public function alt_gruptaki_kayit_ana_gruba_suruklenemez(): void
    {
        [, $alt, [$a, $b], $ana] = $this->sahne();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.reorder'), ['sira' => [$ana->uuid, $a->uuid, $b->uuid]])
            ->assertStatus(422);

        $this->assertNull($ana->fresh()->alt_grup_id);
        $this->assertSame(['A', 'B', 'C', 'D'], $this->dizilim($alt));
    }

    #[Test]
    public function duzenleme_yetkisi_olan_yonetici_degilse_siralayamaz(): void
    {
        [$grup, $alt, [$a, $b]] = $this->sahne();

        $rol = Role::create([
            'slug' => 'duzenleyen', 'label' => 'Düzenleyen', 'badge' => 'gray', 'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => [$grup->name => ['view', 'update']]]],
        ]);
        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);
        $this->assertTrue($kullanici->can('update', $a), 'sahne: kayıt düzenleme yetkisi var');

        $this->actingAs($kullanici)
            ->put(route('credentials.reorder'), ['sira' => [$b->uuid, $a->uuid]])
            ->assertForbidden();

        $this->assertSame(['A', 'B', 'C', 'D'], $this->dizilim($alt));

        $this->actingAs($kullanici)
            ->get(route('credentials.index', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertDontSee('data-order-id', false)
            ->assertDontSee('class="handle"', false);
    }

    #[Test]
    public function yonetici_listede_iki_katmani_da_surukler(): void
    {
        [$grup, $alt, [$a]] = $this->sahne();

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['grup' => $grup->uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-order="'.route('groups.sub.reorder', $grup).'"', $html, 'bölümler sürüklenir');
        $this->assertStringContainsString('data-order-id="'.$alt->uuid.'"', $html);
        $this->assertStringContainsString('data-order="'.route('credentials.reorder').'"', $html, 'kayıtlar sürüklenir');
        $this->assertStringContainsString('data-order-id="'.$a->uuid.'"', $html);
        $this->assertSame(1 + 5, substr_count($html, 'class="handle"'), 'bir bölüm başlığı + beş satır');

        /* Aramada sürükleme yok: süzülmüş liste. */
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['grup' => $grup->uuid, 'q' => 'A']))
            ->assertDontSee('data-order-id', false);
    }

    #[Test]
    public function alt_grup_eksik_listeyle_siralaninca_gelmeyen_yerinde_kalir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup B']);
        [$x, $y, $z] = collect(['X', 'Y', 'Z'])->map(fn (string $ad, int $i) => SubGroup::factory()
            ->for($grup, 'group')->create(['name' => $ad, 'position' => $i + 1]))->all();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('groups.sub.reorder', $grup), ['sira' => [$z->uuid, $x->uuid]])
            ->assertRedirect();

        $this->assertSame(['Z', 'Y', 'X'], $grup->altGruplar()->pluck('name')->all());
    }

    #[Test]
    public function formda_sira_alani_yok_ve_duzenleme_sirayi_bozmaz(): void
    {
        [$grup, $alt, [$a]] = $this->sahne();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('credentials.index', ['grup' => $grup->uuid, 'kayit' => $a->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertDontSee('name="position"', false);

        $this->actingAs($admin)->put(route('credentials.update', $a), [
            'group' => $grup->uuid, 'alt_grup' => $alt->uuid, 'name' => 'A',
            'access_type' => $a->access_type->value, 'operating_system' => $a->operating_system->value,
            'position' => 9999,
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $a->fresh()->position, 'gönderilen sıra yok sayılır');
    }

    #[Test]
    public function yeni_ve_tasinan_kayit_bolumun_sonuna_gider(): void
    {
        [$grup, $alt, , $ana] = $this->sahne();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('credentials.store'), [
            'group' => $grup->uuid, 'alt_grup' => $alt->uuid, 'name' => 'Yeni',
            'access_type' => $ana->access_type->value, 'operating_system' => $ana->operating_system->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['A', 'B', 'C', 'D', 'Yeni'], $this->dizilim($alt));

        $this->actingAs($admin)->put(route('credentials.update', $ana), [
            'group' => $grup->uuid, 'alt_grup' => $alt->uuid, 'name' => 'Ana',
            'access_type' => $ana->access_type->value, 'operating_system' => $ana->operating_system->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['A', 'B', 'C', 'D', 'Yeni', 'Ana'], $this->dizilim($alt));
    }
}
