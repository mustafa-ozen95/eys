<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Panel yetkileri.
 *
 * "Kullanıcıları düzenle" yetkisi, kendini yönetici yapma yetkisi DEĞİLDİR;
 * grup açma yetkisi de yalnızca yöneticidedir çünkü grup aynı zamanda yetki
 * sınırıdır.
 */
class PanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * "Görebilen" sayısı doğru sayılmalı.
     *
     * Sayı Blade'de closure ile hesaplanıyordu; controller'a taşındı. Sayının
     * kendisi bir yetki özetidir: yanlış sayı, yöneticiye grubun kime açık
     * olduğu konusunda yanlış bilgi verir.
     */
    #[Test]
    public function grup_listesi_gorebilen_kullanici_sayisini_gosterir(): void
    {
        $admin = User::factory()->admin()->create();
        $group = CredentialGroup::factory()->create(['name' => 'Sayilan grup']);

        $goren = User::factory()->create();
        $goren->permissions()->create([
            'resource' => 'credential_group',
            'resource_id' => $group->getKey(),
            'action' => 'view',
            'effect' => PermissionEffect::Allow,
        ]);

        /* Yetkisiz kullanıcı sayılmamalı; yönetici hepsini görür, sayılmalı. */
        User::factory()->create();

        $this->actingAs($admin)
            ->get(route('groups.index'))
            ->assertOk()
            ->assertSee('Sayilan grup')
            /* Görebilen sayısı bölüm başlığında yazar. */
            ->assertSee('2 görebilen');
    }

    /**
     * Menü sırası: günlük iş bölümleri önce, yapılandırma sonda.
     *
     * "Ayarlar" ortadaydı; alışılmış yer en sondur ve orada aranır.
     */
    #[Test]
    public function ayarlar_menude_en_sonda_durur(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index'))
            ->assertOk()
            ->getContent();

        preg_match_all('/class="topbar__link"[^>]*>([^<]+)</', (string) preg_replace('/\s+/', ' ', $html), $m);
        $baslıklar = array_map('trim', $m[1]);

        $this->assertSame('Ayarlar', end($baslıklar), 'Ayarlar son sırada olmalı. Sıra: '.implode(' | ', $baslıklar));
        $this->assertSame('Erişim bilgileri', $baslıklar[0]);
    }

    #[Test]
    public function yonetici_olmayan_panel_ekranlarina_giremez(): void
    {
        foreach ([User::factory(), User::factory()->network()] as $factory) {
            $user = $factory->create();
            $group = CredentialGroup::factory()->create();

            $this->actingAs($user)->get(route('groups.index'))->assertForbidden();
            $this->actingAs($user)->post(route('groups.store'), ['name' => 'X', 'kind' => 'other'])->assertForbidden();
            $this->actingAs($user)->get(route('groups.edit', $group))->assertForbidden();
            $this->actingAs($user)->get(route('users.index'))->assertForbidden();
            $this->actingAs($user)->get(route('users.create'))->assertForbidden();
            $this->actingAs($user)->get(route('logs.index'))->assertForbidden();
            $this->actingAs($user)->get(route('users.permissions.edit', $user))->assertForbidden();
        }
    }

    #[Test]
    public function yonetici_kendi_rolunu_degistiremez(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => Role::query()->where('slug', 'teknik')->value('uuid'),
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('admin', $admin->fresh()->role->slug, 'Kendi rolü değişmemeli.');
    }

    #[Test]
    public function yonetici_kendini_silemez(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
        $this->assertModelExists($admin);
    }

    #[Test]
    public function yonetici_baska_kullanicinin_rolunu_degistirebilir(): void
    {
        $admin = User::factory()->admin()->create();
        $hedef = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('users.update', $hedef), [
                'name' => $hedef->name,
                'email' => $hedef->email,
                'role' => Role::query()->where('slug', 'network')->value('uuid'),
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('network', $hedef->fresh()->role->slug);
    }

    /**
     * ROLÜN YETKİSİ CANLI: rolü düzenlemek o roldeki herkes için ANINDA
     * geçerli olur.
     *
     * Yetkiler kullanıcıya kopyalanmaz; kopya olsaydı rolü düzenlemek
     * kimseyi etkilemez, tek tek "şablonu uygula" demek gerekirdi.
     */
    #[Test]
    public function rolun_yetkisi_canlidir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $network = Role::query()->where('slug', 'network')->firstOrFail();
        $network->update(['template' => []]);

        $hedef = User::factory()->create(['role_id' => $network->id]);
        $kayit = Credential::factory()->for($grup, 'group')->create();

        $this->assertFalse($hedef->can('view', $kayit));

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('roles.update', $network), [
                'label' => $network->label,
                'slug' => $network->slug,
                'badge' => $network->badge->value,
                'template_all' => ['view'],
                'template_groups' => [],
            ])
            ->assertSessionHasNoErrors();

        app(UserPermissionResolver::class)->flush($hedef->fresh());

        $this->assertTrue($hedef->fresh()->can('view', $kayit), 'Rolün yeni hâli hemen geçerli olmalı.');
    }

    /**
     * ROL DEĞİŞİNCE YETKİLER DE ONUNLA GELİR.
     *
     * Kopya olmadığı için ayrıca bir şey uygulamak gerekmez: kişinin rolü
     * değişti, rolün yetkisi geçerli.
     */
    #[Test]
    public function rol_degisince_yeni_rolun_yetkileri_gecerli_olur(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup A']);

        $network = Role::query()->where('slug', 'network')->firstOrFail();
        $network->update(['template' => ['credential_group' => ['all' => ['view'], 'groups' => []]]]);

        $hedef = User::factory()->create(['role_id' => Role::query()->where('slug', 'yetkisiz')->value('id')]);
        $kayit = Credential::factory()->for($grup, 'group')->create();

        $this->assertFalse($hedef->can('view', $kayit));

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.update', $hedef), [
                'name' => $hedef->name,
                'email' => $hedef->email,
                'role' => $network->uuid,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        app(UserPermissionResolver::class)->flush($hedef->fresh());

        $this->assertTrue($hedef->fresh()->can('view', $kayit));
    }

    /** Kişisel istisnalar rol değişince de yerinde kalır: onlar kişiye verildi. */
    #[Test]
    public function kisisel_istisnalar_rol_degisince_de_kalir(): void
    {
        $hedef = User::factory()->create();
        $grup = CredentialGroup::factory()->create();

        app(UserPermissionResolver::class)->syncResource($hedef, 'credential_group', [], ['view' => [$grup->id]]);
        $once = $hedef->fresh()->permissions()->count();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('users.update', $hedef), [
                'name' => 'Yeni Ad',
                'email' => $hedef->email,
                'role' => Role::query()->where('slug', 'network')->value('uuid'),
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($once, $hedef->fresh()->permissions()->count());
    }

    #[Test]
    public function alan_yetkisi_grup_bazinda_verilir(): void
    {
        $admin = User::factory()->admin()->create();
        $hedef = User::factory()->create();

        $grupB = CredentialGroup::factory()->create(['name' => 'Grup B']);
        $grupA = CredentialGroup::factory()->create(['name' => 'Grup A']);

        /*
         * Her grupta gör ve adresi gör; parolayı YALNIZCA Grup A'da aç,
         * Grup B'de görmeyi tamamen kes.
         */
        $this->actingAs($admin)
            ->put(route('users.permissions.update', $hedef), [
                'resource' => 'credential_group',
                'wildcard' => ['view' => 'allow', 'field.host' => 'allow'],
                'cells' => [
                    'field.password' => [$grupA->uuid => 'allow'],
                    'view' => [$grupB->uuid => 'deny'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $grupBKaydi = Credential::factory()->for($grupB, 'group')->create();
        $grupAKaydi = Credential::factory()->for($grupA, 'group')->create();

        $hedef = $hedef->fresh();

        $this->assertFalse($hedef->can('view', $grupBKaydi), 'Grup B yasaklı: görünmemeli.');
        $this->assertTrue($hedef->can('view', $grupAKaydi));
        $this->assertTrue($hedef->can('reveal', $grupAKaydi));
        $this->assertFalse($hedef->can('reveal', $grupBKaydi));
    }

    #[Test]
    public function alan_yetkisi_gorme_yetkisi_olmadan_ise_yaramaz(): void
    {
        $admin = User::factory()->admin()->create();
        $hedef = User::factory()->create();
        $grup = CredentialGroup::factory()->create();

        /* Parola izni var ama görme izni yok: parola AÇILAMAZ. */
        $this->actingAs($admin)
            ->put(route('users.permissions.update', $hedef), [
                'resource' => 'credential_group',
                'wildcard' => ['field.password' => 'allow'],
            ])
            ->assertSessionHasNoErrors();

        $kayit = Credential::factory()->for($grup, 'group')->create();

        $this->assertFalse($hedef->fresh()->can('reveal', $kayit));
    }

    /**
     * ROLÜN YETKİSİ KENDİLİĞİNDEN GEÇERLİDİR: uygulanacak bir şablon yok.
     *
     * Teknik rolü görür ve adresi görür, parolayı görmez — kullanıcıya tek
     * bir yetki satırı yazılmadan.
     */
    #[Test]
    public function rolun_yetkisi_kendiliginden_gecerlidir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup B']);
        $hedef = User::factory()->teknik()->create();

        Role::query()->where('slug', 'teknik')->firstOrFail()->update([
            'template' => ['credential_group' => [
                'all' => ['view', 'field.host'],
                'groups' => [],
            ]],
        ]);

        $kayit = Credential::factory()->for($grup, 'group')->create();
        $hedef = $hedef->fresh();

        $this->assertSame(0, $hedef->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue($hedef->can('view', $kayit));
        $this->assertFalse($hedef->can('reveal', $kayit));
    }

    #[Test]
    public function zayif_parola_ile_kullanici_acilamaz(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('users.store'), [
                'name' => 'Test',
                'email' => 'test@example.com',
                'password' => 'kisa',
                'password_confirmation' => 'kisa',
                'role' => Role::query()->where('slug', 'teknik')->value('uuid'),
                'status' => 'active',
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * İSTİSNA EKRANI ROLÜN NE DEDİĞİNİ DE GÖSTERİR.
     *
     * Yalnızca istisnaları gösterseydi, "boş" bir ekran gören yönetici o
     * kişinin hiçbir yetkisi olmadığını sanırdı.
     */
    #[Test]
    public function istisna_ekrani_rolun_verdigini_gosterir(): void
    {
        CredentialGroup::factory()->create(['name' => 'Grup A']);

        $network = Role::query()->where('slug', 'network')->firstOrFail();
        $network->update(['template' => ['credential_group' => ['all' => ['view'], 'groups' => []]]]);

        $hedef = User::factory()->create(['role_id' => $network->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('users.permissions.edit', $hedef))
            ->assertOk()
            ->assertSee('rolde var')
            ->assertSee('Rolden gelsin');
    }
}
