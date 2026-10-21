<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAYIT BAZINDA GÖRME VE PORTAL İZNİ.
 *
 * Örnek durum: bir kayıt, rolün gördüğü bir gruptan rolün görmediği başka
 * bir gruba taşınır; rol o kaydı kaybeder. Hedef grubun geri kalanı ise o
 * role kapalı kalmalı. Grup izni ikisini ayıramıyor, kayıt izni ayırıyor.
 *
 * SINANAN ASIL ŞEY, İZNİN DAR KALMASI: izinli kayıt görünür, aynı gruptaki
 * öteki kayıt görünmez, parola hiçbir yerde açılmaz.
 */
class RecordBasedViewPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Credential, 2: Credential, 3: Role, 4: CredentialGroup} */
    private function sahne(bool $portal = true): array
    {
        $vm = CredentialGroup::factory()->create(['name' => 'Grup A']);

        $rol = Role::create([
            'slug' => 'dar-rol',
            'label' => 'Dar rol',
            'badge' => 'blue',
            'is_admin' => false,
            /* Grup A'da HİÇBİR izin yok. */
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => ['Başka' => ['view']]]],
        ]);

        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);

        /* Sınama verisi uydurmadır (bkz. RecordBasedPasswordPermissionTest). */
        $izinli = Credential::factory()->create([
            'credential_group_id' => $vm->getKey(), 'name' => 'Izinli kayit',
            'host' => 'sinama.ornek.test', 'username' => 'sinama-kullanicisi', 'password' => 'SINAMA-PAROLASI',
        ]);

        $oteki = Credential::factory()->create([
            'credential_group_id' => $vm->getKey(), 'name' => 'Oteki kayit',
            'username' => 'root', 'password' => 'OTEKI-PAROLASI',
        ]);

        $izinli->grantedRoles()->attach($rol, ['action' => 'view']);

        if ($portal) {
            $izinli->grantedRoles()->attach($rol, ['action' => 'session']);
        }

        return [$kullanici, $izinli, $oteki, $rol, $vm];
    }

    #[Test]
    public function izinli_kayit_gorunur_otekisi_gorunmez_parola_acilmaz(): void
    {
        [$kullanici, $izinli, $oteki] = $this->sahne();

        $this->assertTrue($kullanici->can('view', $izinli));
        $this->assertFalse($kullanici->can('view', $oteki));
        $this->assertFalse($kullanici->can('reveal', $izinli), 'görme izni parolayı açmamalı');

        $this->assertSame(
            [$izinli->id],
            Credential::query()->visibleTo($kullanici)->pluck('id')->all(),
        );

        $alanlar = $izinli->visibleFieldsFor($kullanici);
        $this->assertTrue($alanlar['host'] ?? false, 'adres görünmeli');
        $this->assertFalse($alanlar['password'] ?? false, 'parola görünmemeli');
        $this->assertFalse($alanlar['username'] ?? false, 'kullanıcı adı parolayla birlikte kapalı');
    }

    #[Test]
    public function grup_kenar_cubugunda_yalniz_izinli_kayitla_gorunur(): void
    {
        [$kullanici, , , , $vm] = $this->sahne();

        $grup = CredentialGroup::query()->visibleTo($kullanici)
            ->withCount(['credentials' => fn ($q) => $q->visibleTo($kullanici)])
            ->whereKey($vm->getKey())->first();

        $this->assertNotNull($grup, 'Grup A kenar çubuğunda görünmeli');
        $this->assertSame(1, $grup->credentials_count);
    }

    #[Test]
    public function liste_ekrani_izinli_kaydi_gosterir_parolayi_gostermez(): void
    {
        [$kullanici, , , , $vm] = $this->sahne();

        $this->actingAs($kullanici)
            ->get(route('credentials.index', ['grup' => $vm->uuid]))
            ->assertOk()
            ->assertSee('Izinli kayit')
            ->assertDontSee('Oteki kayit')
            ->assertDontSee('SINAMA-PAROLASI')
            ->assertDontSee('OTEKI-PAROLASI');
    }

    #[Test]
    public function portal_izni_ayri_verilir(): void
    {
        [$kullanici, $izinli] = $this->sahne(portal: false);
        $this->assertFalse($kullanici->can('arayuz', $izinli), 'portal izni yokken arayüz açılmamalı');

        $izinli->grantedRoles()->attach($kullanici->role_id, ['action' => 'session']);
        $this->assertTrue($kullanici->fresh()->can('arayuz', $izinli->fresh()));
    }

    #[Test]
    public function kisit_gorme_iznini_yener(): void
    {
        [$kullanici, $izinli, , $rol, $vm] = $this->sahne();
        $izinli->restrictedRoles()->attach($rol);

        $this->assertFalse($kullanici->fresh()->can('view', $izinli->fresh()));
        $this->assertSame(0, Credential::query()->visibleTo($kullanici)->count());
        $this->assertNull(CredentialGroup::query()->visibleTo($kullanici)->whereKey($vm->getKey())->first());
    }

    #[Test]
    public function kisisel_kayit_izinle_acilmaz(): void
    {
        [$kullanici, , , $rol] = $this->sahne();
        $sahip = User::factory()->create();
        $kisisel = Credential::factory()->create([
            'credential_group_id' => CredentialGroup::factory()->create(['is_personal' => true])->getKey(),
            'owner_id' => $sahip->getKey(), 'name' => 'Başkasının kaydı',
        ]);
        $kisisel->grantedRoles()->attach($rol, ['action' => 'view']);

        $this->assertFalse($kullanici->can('view', $kisisel));
        $this->assertNotContains($kisisel->id, Credential::query()->visibleTo($kullanici)->pluck('id')->all());
    }

    #[Test]
    public function yonetici_formdan_yazar_ve_tikler_kalkinca_siler(): void
    {
        [$kullanici, , $oteki, $rol] = $this->sahne();
        $admin = User::factory()->admin()->create();
        $alanlar = [
            'group' => $oteki->group->uuid,
            'name' => $oteki->name,
            'access_type' => $oteki->access_type->value,
            'operating_system' => $oteki->operating_system->value,
            'rol_kutulari' => '1',
        ];

        $this->actingAs($admin)
            ->put(route('credentials.update', $oteki), [...$alanlar, 'gorme_roles' => [$rol->uuid], 'portal_roles' => [$rol->uuid]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($kullanici->can('view', $oteki->fresh()));
        $this->assertSame(['session', 'view'], DB::table('credential_role_grants')
            ->where('credential_id', $oteki->id)->orderBy('action')->pluck('action')->all());

        $this->actingAs($admin)->put(route('credentials.update', $oteki), $alanlar)->assertSessionHasNoErrors();

        $this->assertFalse($kullanici->can('view', $oteki->fresh()));
        $this->assertSame(0, DB::table('credential_role_grants')->where('credential_id', $oteki->id)->count());
    }

    #[Test]
    public function yonetici_olmayan_izin_yazamaz(): void
    {
        [$kullanici, , $oteki, $rol] = $this->sahne();

        $this->actingAs($kullanici)->put(route('credentials.update', $oteki), [
            'group' => $oteki->group->uuid, 'name' => $oteki->name,
            'access_type' => $oteki->access_type->value, 'operating_system' => $oteki->operating_system->value,
            'gorme_roles' => [$rol->uuid],
        ]);

        $this->assertSame(0, DB::table('credential_role_grants')
            ->where('credential_id', $oteki->id)->where('action', 'view')->count());
    }

    /**
     * Rol başına TEK SEÇİM: kısıt ile görme izni aynı anda yazılamaz.
     */
    #[Test]
    public function rol_basina_tek_secim_kisiti_ve_izni_yazar(): void
    {
        [$kullanici, , $oteki, $rol] = $this->sahne();
        $admin = User::factory()->admin()->create();
        $alanlar = [
            'group' => $oteki->group->uuid,
            'name' => $oteki->name,
            'access_type' => $oteki->access_type->value,
            'operating_system' => $oteki->operating_system->value,
            'rol_kutulari' => '1',
        ];
        $kisit = fn () => DB::table('credential_role_restrictions')->where('credential_id', $oteki->id)->count();
        $izin = fn () => DB::table('credential_role_grants')->where('credential_id', $oteki->id)->where('action', 'view')->count();

        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_gorunum' => [$rol->uuid => 'gorur']])
            ->assertSessionHasNoErrors();
        $this->assertSame([0, 1], [$kisit(), $izin()]);
        $this->assertTrue($kullanici->can('view', $oteki->fresh()));

        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_gorunum' => [$rol->uuid => 'gizli']])
            ->assertSessionHasNoErrors();
        $this->assertSame([1, 0], [$kisit(), $izin()], 'gizliye geçince izin kalkmalı');

        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_gorunum' => [$rol->uuid => 'grup']])
            ->assertSessionHasNoErrors();
        $this->assertSame([0, 0], [$kisit(), $izin()]);

        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_gorunum' => [$rol->uuid => 'baska']])
            ->assertSessionHasErrors('rol_gorunum.'.$rol->uuid);
    }

    #[Test]
    public function form_tablosu_kaydin_durumunu_gosterir(): void
    {
        [, $izinli, , $rol] = $this->sahne();

        view()->share('errors', new ViewErrorBag);

        /* Bileşen sınıflı: hesaplar sınıfta yapılır, o yüzden etiketle çizilir. */
        $html = (string) $this->blade(
            '<x-credentials.form :credential="$credential" :can-restrict="true" :restrictable-roles="$roller"
                :rol-gorunum="$gorunum" :group-options="[]" :access-types="[]" :operating-systems="[]" />',
            ['credential' => $izinli, 'roller' => collect([$rol]), 'gorunum' => [$rol->uuid => 'gorur']],
        );

        $this->assertMatchesRegularExpression('/name="rol_gorunum\['.$rol->uuid.'\]".*?<option value="gorur"\s+selected/s', $html);
        $this->assertStringNotContainsString('restricted_roles[]', $html, 'eski kutu listesi kalkmalı');
        $this->assertStringNotContainsString('gorme_roles[]', $html);
    }
}
