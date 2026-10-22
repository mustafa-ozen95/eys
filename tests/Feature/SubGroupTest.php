<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\ApiToken;
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
 * ALT GRUPLAR: grubun içindeki bölmeler (ör. Yedekleme → Yedek 1, Yedek 2).
 *
 * Grup olduğu gibi kalır ve yetkiyi o taşır; alt grup yalnızca listede
 * başlık olarak görünür. Var olan gruplar üst grup olarak kalır, alt gruplar
 * yönetilebilir.
 */
class SubGroupTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        $rol = Role::query()->where('is_admin', true)->first()
            ?? Role::create(['slug' => 'admin', 'label' => 'Yönetici', 'badge' => 'gray', 'is_admin' => true]);

        return User::factory()->create(['role_id' => $rol->getKey()]);
    }

    private function yetkilendir(User $user, CredentialGroup $group, array $actions): void
    {
        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => CredentialPolicy::RESOURCE,
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $user->unsetRelation('permissions');
    }

    /** @return array{0: CredentialGroup, 1: SubGroup, 2: SubGroup} */
    private function yedekleme(): array
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Yedekleme']);
        $bir = SubGroup::factory()->for($grup, 'group')->create(['name' => 'Yedek 1', 'position' => 10]);
        $iki = SubGroup::factory()->for($grup, 'group')->create(['name' => 'Yedek 2', 'position' => 20]);

        Credential::factory()->for($grup, 'group')->create(['name' => 'Kayit-Iki', 'alt_grup_id' => $iki->getKey()]);
        Credential::factory()->for($grup, 'group')->create(['name' => 'Kayit-Bir', 'alt_grup_id' => $bir->getKey()]);
        Credential::factory()->for($grup, 'group')->create(['name' => 'Kayit-Ortak']);

        return [$grup, $bir, $iki];
    }

    /** Gruplar sayfasında bütün gruplar kapalı gelir; yalnız ?acik= ile istenen açılır. */
    #[Test]
    public function gruplar_sayfasinda_hepsi_kapali_gelir_acik_parametresiyle_acilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        CredentialGroup::factory()->create(['name' => 'Birinci', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci', 'position' => 2]);

        $sayfa = $this->actingAs($yonetici)->get(route('groups.index'))->assertOk()->getContent();
        $this->assertSame(0, preg_match_all('/<details[^>]*\bopen\b/', $sayfa));

        $sayfa = $this->actingAs($yonetici)->get(route('groups.index', ['acik' => $ikinci->uuid]))->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/<details[^>]*\bopen\b/', $sayfa));
    }

    #[Test]
    public function grup_secilince_kayitlar_alt_grup_basliklari_altinda_siralanir(): void
    {
        [$grup] = $this->yedekleme();

        $this->actingAs($this->yonetici())
            ->get(route('credentials.index', ['grup' => $grup->uuid]))
            ->assertOk()
            /* Önce Yedek 1 (sıra 10), sonra Yedek 2, bölmesiz kayıt en sonda. */
            ->assertSeeInOrder(['Yedek 1', 'Kayit-Bir', 'Yedek 2', 'Kayit-Iki', 'Alt grupsuz', 'Kayit-Ortak']);
    }

    /**
     * Kenar çubuğunda alt grup YOK; bölmeler listenin içinde başlık olarak
     * durur.
     */
    #[Test]
    public function kenar_cubugunda_alt_gruplar_listelenmez_listede_baslik_olur(): void
    {
        [$grup] = $this->yedekleme();

        $this->actingAs($this->yonetici())
            ->get(route('credentials.index', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertDontSee('row-item--alt', false)
            /* Başlık tıklanmaz: bağlantı değil, düz başlık. */
            ->assertSee('<div class="credential-section">', false)
            ->assertDontSee('<a href="http://localhost/access?grup='.$grup->uuid.'&amp;alt=', false);
    }

    /**
     * ALT GRUP YETKİ TAŞIMAZ ama sayıları da yetkiyi aşmaz: bölmenin sayısı
     * kullanıcının gerçekten görebildiği kayıt sayısıdır.
     */
    #[Test]
    public function alt_grup_sayisi_kullanicinin_gorebildigi_kayitlari_sayar(): void
    {
        [$grup, $bir, $iki] = $this->yedekleme();

        $rol = Role::create(['slug' => 'kisitli', 'label' => 'Kısıtlı', 'badge' => 'gray', 'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => ['Yedekleme' => ['view']]]]]);
        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);

        /* Yedek 2'deki tek kayıt bu role kapalı. */
        Credential::query()->where('alt_grup_id', $iki->getKey())->first()->restrictedRoles()->attach($rol);

        $yanit = $this->actingAs($kullanici)
            ->get(route('credentials.index', ['grup' => $grup->uuid]))
            ->assertOk()
            ->assertSee('Kayit-Bir')
            ->assertDontSee('Kayit-Iki');

        $this->assertSame(0, $yanit->viewData('groups')->firstWhere('uuid', $grup->uuid)
            ->altGruplar->firstWhere('uuid', $iki->uuid)->credentials_count);
    }

    #[Test]
    public function hepsi_gorunumunde_satir_grup_ve_alt_grubunu_yazar(): void
    {
        $this->yedekleme();

        $this->actingAs($this->yonetici())
            ->get(route('credentials.index', ['grup' => 'hepsi']))
            ->assertOk()
            ->assertSee('Yedekleme · Yedek 1');
    }

    #[Test]
    public function kayit_baska_grubun_alt_grubuna_baglanamaz(): void
    {
        [$grup] = $this->yedekleme();
        $baska = CredentialGroup::factory()->create(['name' => 'Başka']);
        $yabanci = SubGroup::factory()->for($baska, 'group')->create(['name' => 'Yabancı']);
        $kendi = $grup->altGruplar->first();

        $veri = fn (string $alt): array => [
            'group' => $grup->uuid, 'alt_grup' => $alt, 'name' => 'Yeni', 'access_type' => 'ssh',
            'operating_system' => 'linux', 'host' => '10.0.0.9', 'is_active' => '1', 'position' => 0,
        ];

        $this->actingAs($this->yonetici())
            ->post(route('credentials.store'), $veri($yabanci->uuid))
            ->assertSessionHasErrors('alt_grup');

        $this->actingAs($this->yonetici())
            ->post(route('credentials.store'), $veri($kendi->uuid))
            ->assertSessionHasNoErrors();

        $this->assertSame($kendi->getKey(), Credential::query()->where('name', 'Yeni')->value('alt_grup_id'));
    }

    #[Test]
    public function yonetici_alt_grup_ekler_duzenler_ve_kaldirir_kayitlar_grupta_kalir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Konsol']);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)
            ->post(route('groups.sub.store', $grup), ['alt_ad' => 'Konsol 1'])
            ->assertRedirect(route('groups.index', ['acik' => $grup->uuid]))
            ->assertSessionHasNoErrors();

        $alt = $grup->altGruplar()->firstOrFail();
        $this->assertSame('Konsol 1', $alt->name);

        /* Aynı grupta aynı ad iki kez olmaz. */
        $this->actingAs($yonetici)
            ->post(route('groups.sub.store', $grup), ['alt_ad' => 'Konsol 1'])
            ->assertSessionHasErrors('alt_ad');

        $this->actingAs($yonetici)
            ->put(route('groups.sub.update', [$grup, $alt]), ['alt_ad' => 'Konsol Bir'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Konsol Bir', $alt->fresh()->name);

        $kayit = Credential::factory()->for($grup, 'group')->create(['alt_grup_id' => $alt->getKey()]);

        $this->actingAs($yonetici)
            ->delete(route('groups.sub.destroy', [$grup, $alt]))
            ->assertRedirect(route('groups.index', ['acik' => $grup->uuid]));

        $this->assertDatabaseMissing('alt_gruplar', ['id' => $alt->getKey()]);
        $this->assertNull($kayit->fresh()->alt_grup_id, 'Alt grup kalkınca kayıt grupta, başlıksız kalmalı.');
        $this->assertSame($grup->getKey(), $kayit->fresh()->credential_group_id);
    }

    /**
     * Alt gruplar GRUPLAR sayfasında, grubun bölümünde yönetilir; grup
     * düzenleme sayfasında değil.
     */
    #[Test]
    public function gruplar_sayfasi_alt_gruplari_bolum_icinde_listeler_ve_pencereyle_duzenler(): void
    {
        [$grup, $bir, $iki] = $this->yedekleme();
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)
            ->get(route('groups.index', ['acik' => $grup->uuid]))
            ->assertOk()
            ->assertSee('Yedek 1')
            ->assertSee('Yedek 2')
            ->assertSee('data-order-id="'.$grup->uuid.'"', false)
            ->assertSee('data-order-id="'.$bir->uuid.'"', false)
            ->assertSee(route('groups.sub.destroy', [$grup, $iki]), false)
            ->assertDontSee('id="sub-group-window"', false);

        /* Ekleme penceresi sunucudan açık gelir. */
        $this->actingAs($yonetici)
            ->get(route('groups.index', ['acik' => $grup->uuid, 'pencere' => 'ekle', 'grup' => $grup->uuid]))
            ->assertOk()
            ->assertSee('id="sub-group-window"', false)
            ->assertSee(route('groups.sub.store', $grup), false);

        /* Düzenleme penceresi kaydın adıyla gelir. */
        $this->actingAs($yonetici)
            ->get(route('groups.index', ['acik' => $grup->uuid, 'pencere' => 'duzenle', 'alt' => $bir->uuid]))
            ->assertOk()
            ->assertSee(route('groups.sub.update', [$grup, $bir]), false)
            ->assertSee('value="Yedek 1"', false);

        $this->actingAs($yonetici)
            ->get(route('groups.edit', $grup))
            ->assertOk()
            ->assertDontSee(route('groups.sub.store', $grup), false);
    }

    /**
     * Sıra sürükleyerek verilir; alt gruplar için de.
     */
    #[Test]
    public function gruplar_ve_alt_gruplar_surukleyerek_siralanir(): void
    {
        [$grup, $bir, $iki] = $this->yedekleme();
        $baska = CredentialGroup::factory()->create(['name' => 'Başka', 'position' => 5]);
        $yabanci = SubGroup::factory()->for($baska, 'group')->create(['name' => 'Yabancı', 'position' => 1]);

        $this->actingAs($this->yonetici())
            ->put(route('groups.reorder'), ['sira' => [$grup->uuid, $baska->uuid]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Yedekleme', 'Başka'], CredentialGroup::query()->where('is_personal', false)->ordered()->pluck('name')->all());

        /* Başka grubun bölmesi listeye karışsa bile yok sayılır. */
        $this->actingAs($this->yonetici())
            ->put(route('groups.sub.reorder', $grup), ['sira' => [$iki->uuid, $yabanci->uuid, $bir->uuid]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Yedek 2', 'Yedek 1'], $grup->altGruplar()->pluck('name')->all());
        $this->assertSame(1, $yabanci->fresh()->position);

        /* Yeni bölme listenin sonuna girer. */
        $this->actingAs($this->yonetici())->post(route('groups.sub.store', $grup), ['alt_ad' => 'Yedek 3']);
        $this->assertSame(['Yedek 2', 'Yedek 1', 'Yedek 3'], $grup->altGruplar()->pluck('name')->all());

        $this->actingAs(User::factory()->create())
            ->put(route('groups.reorder'), ['sira' => [$grup->uuid]])
            ->assertForbidden();
    }

    #[Test]
    public function alt_grup_adresi_baska_grubun_altindan_cozulmez(): void
    {
        [$grup, $bir] = $this->yedekleme();
        $baska = CredentialGroup::factory()->create(['name' => 'Başka']);

        $this->actingAs($this->yonetici())
            ->put(route('groups.sub.update', [$baska, $bir]), ['alt_ad' => 'Çalındı'])
            ->assertNotFound();

        $this->assertSame('Yedek 1', $bir->fresh()->name);
    }

    #[Test]
    public function yonetici_olmayan_alt_grup_yonetemez(): void
    {
        [$grup, $bir] = $this->yedekleme();
        $kullanici = User::factory()->create();
        $this->yetkilendir($kullanici, $grup, ['view', 'update']);

        $this->actingAs($kullanici)
            ->post(route('groups.sub.store', $grup), ['alt_ad' => 'X'])
            ->assertForbidden();

        $this->actingAs($kullanici)
            ->delete(route('groups.sub.destroy', [$grup, $bir]))
            ->assertForbidden();
    }

    /**
     * Grubu yeniden adlandırmak rol şablonunu düşürmemeli: şablon grubu
     * adıyla tanıyor, ad değişince yetki sessizce gidiyordu.
     */
    #[Test]
    public function grup_yeniden_adlandirilinca_rol_sablonu_yeni_adi_ogrenir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Yedek 1', 'kind' => 'server']);
        $rol = Role::create(['slug' => 'izleyici', 'label' => 'İzleyici', 'badge' => 'gray', 'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => ['Yedek 1' => ['view', 'field.host']]]]]);
        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);
        $kayit = Credential::factory()->for($grup, 'group')->create();

        $this->assertTrue($kullanici->can('view', $kayit));

        $this->actingAs($this->yonetici())
            ->put(route('groups.update', $grup), ['name' => 'Yedekleme', 'kind' => 'server'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['Yedekleme' => ['view', 'field.host']],
            $rol->fresh()->normalizedTemplate()['groups'],
        );

        $this->assertTrue(
            User::query()->findOrFail($kullanici->getKey())->can('view', $kayit->fresh()),
            'Ad değişince rolün gruptaki yetkisi sürmeli.',
        );
    }

    #[Test]
    public function grup_silinince_rol_sablonundaki_satiri_da_gider(): void
    {
        $kalan = CredentialGroup::factory()->create(['name' => 'Kalan']);
        $giden = CredentialGroup::factory()->create(['name' => 'Giden']);
        $rol = Role::create(['slug' => 'izleyici', 'label' => 'İzleyici', 'badge' => 'gray', 'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => [
                'Kalan' => ['view'], 'Giden' => ['view', 'field.host'],
            ]]]]);

        $this->actingAs($this->yonetici())
            ->delete(route('groups.destroy', $giden), ['reason' => 'artık gerekmiyor'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Kalan' => ['view']], $rol->fresh()->normalizedTemplate()['groups']);
        $this->assertDatabaseHas('credential_groups', ['id' => $kalan->getKey()]);
    }

    #[Test]
    public function api_kaydin_alt_grubunu_doner(): void
    {
        [$grup] = $this->yedekleme();
        $yonetici = $this->yonetici();
        $yeni = ApiToken::mint();

        ApiToken::create([
            'name' => 'NYS',
            'user_id' => $yonetici->id,
            'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'],
            'abilities' => [CredentialPolicy::RESOURCE => ['view']],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$yeni['plain'])
            ->getJson('/api/v1/records?grup='.$grup->uuid)
            ->assertOk()
            ->assertJsonFragment(['ad' => 'Kayit-Bir'])
            ->assertJsonPath('data.0.alt_grup.ad', fn (?string $ad): bool => in_array($ad, ['Yedek 1', 'Yedek 2', null], true));
    }
}
