<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\ApiToken;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Export\PasswordOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Yetki katmanlarının birbirini DELMEMESİ.
 *
 * Kayıt bazlı rol izni kişinin açık yasağını ve görme yasağını aşmaz; liste
 * kişiye konmuş genel görme yasağını rol grup izinleri için de uygular; arama
 * maskelenmiş alanları sorgulatmaz; API jetonunun yetenek listesi tek kayıt
 * ucuna ve alan değerlerine de uygulanır; toplu çıktı 'export' ile süzülür.
 */
class PermissionLayersTest extends TestCase
{
    use RefreshDatabase;

    private function ver(User $user, CredentialGroup $group, array $actions, PermissionEffect $etki = PermissionEffect::Allow, ?int $kayitId = null): void
    {
        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $kayitId ?? $group->getKey(),
                'action' => $action,
                'effect' => $etki,
            ]);
        }

        app(UserPermissionResolver::class)->flush($user);
    }

    private function rolVer(Role $rol, Credential $kayit, string $aksiyon): void
    {
        DB::table('credential_role_grants')->insert([
            'credential_id' => $kayit->id, 'role_id' => $rol->id, 'action' => $aksiyon,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ozelRol(): Role
    {
        return Role::create(['slug' => 'ozel-'.uniqid(), 'label' => 'Özel', 'is_admin' => false, 'template' => []]);
    }

    #[Test]
    public function kayit_izni_kisinin_acik_yasagini_asamaz(): void
    {
        $rol = $this->ozelRol();
        $user = User::factory()->create(['role_id' => $rol->id]);
        $grup = CredentialGroup::factory()->create();
        $k = Credential::factory()->for($grup, 'group')->create(['password' => 'GIZLI']);
        $this->rolVer($rol, $k, 'field.password');

        /* Rol görür; kayıt izni parolayı açar. */
        $this->ver($user, $grup, ['view']);
        $this->assertTrue($user->can('reveal', $k));
        $this->actingAs($user)->post(route('ajax.credentials.password', $k))->assertOk();

        /* Kişiye parola AÇIKÇA yasak: kayıt izni bunu delmez. */
        $yasakli = User::factory()->create(['role_id' => $rol->id]);
        $this->ver($yasakli, $grup, ['view']);
        $this->ver($yasakli, $grup, ['field.password'], PermissionEffect::Deny);
        $this->assertFalse($yasakli->can('reveal', $k));
        $this->actingAs($yasakli)->post(route('ajax.credentials.password', $k))->assertForbidden();
    }

    #[Test]
    public function kayit_izni_gorme_olmadan_parola_acmaz(): void
    {
        $rol = $this->ozelRol();
        $grup = CredentialGroup::factory()->create();
        $k = Credential::factory()->for($grup, 'group')->create(['password' => 'GIZLI']);
        $this->rolVer($rol, $k, 'field.password');

        /* Görme yetkisi hiç yok. */
        $gormez = User::factory()->create(['role_id' => $rol->id]);
        $this->assertFalse($gormez->can('reveal', $k));

        /* Görme kişiye yasak. */
        $yasak = User::factory()->create(['role_id' => $rol->id]);
        $this->ver($yasak, $grup, ['view'], PermissionEffect::Deny);
        $this->assertFalse($yasak->can('reveal', $k));
        $this->actingAs($yasak)->post(route('ajax.credentials.password', $k))->assertForbidden();
    }

    #[Test]
    public function kisiye_genel_gorme_yasagi_rolun_grup_izinlerini_listede_de_keser(): void
    {
        $grup = CredentialGroup::factory()->create();
        $k = Credential::factory()->for($grup, 'group')->create();
        $rol = Role::create([
            'slug' => 'gruplu', 'label' => 'Gruplu', 'is_admin' => false,
            'template' => ['credential_group' => ['groups' => [$grup->name => ['view']]]],
        ]);

        $user = User::factory()->create(['role_id' => $rol->id]);
        $this->assertTrue($user->can('view', $k), 'Kurgu: rol grubu açıyor.');
        $this->assertTrue(Credential::query()->visibleTo($user)->whereKey($k->id)->exists());

        $this->ver($user, $grup, ['view'], PermissionEffect::Deny, UserPermission::ALL_RECORDS);

        $this->assertFalse($user->can('view', $k));
        $this->assertFalse(Credential::query()->visibleTo($user)->whereKey($k->id)->exists(), 'Politikanın reddettiği kayıt listede çıkmamalı.');
        $this->actingAs($user)->get(route('credentials.index'))->assertOk()->assertDontSee($k->name);

        /* Kişinin KAYDA ÖZEL izni ise yine geçer. */
        $this->ver($user, $grup, ['view']);
        $this->assertTrue(Credential::query()->visibleTo($user->fresh())->whereKey($k->id)->exists());
    }

    #[Test]
    public function arama_gorulmeyen_alanlarda_eslesmez(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view']);
        $k = Credential::factory()->for($grup, 'group')->create([
            'name' => 'Kayit A', 'host' => '10.99.88.77', 'url' => 'https://gizli-url.test', 'username' => 'qzadmin',
        ]);

        foreach (['qzadm', '10.99.88', 'gizli-url'] as $terim) {
            $this->assertFalse(Credential::query()->visibleTo($user)->search($terim, $user)->whereKey($k->id)->exists(), $terim);
        }

        /* Ad her zaman aranır. */
        $this->assertTrue(Credential::query()->visibleTo($user)->search('Kayit A', $user)->whereKey($k->id)->exists());

        /* Alanı görebilen bulur. */
        $acik = User::factory()->create();
        $this->ver($acik, $grup, ['view', 'field.host', 'field.url', 'field.password']);
        foreach (['qzadm', '10.99.88', 'gizli-url'] as $terim) {
            $this->assertTrue(Credential::query()->visibleTo($acik)->search($terim, $acik)->whereKey($k->id)->exists(), $terim);
        }

        /* Yönetici hepsini bulur. */
        $admin = User::factory()->admin()->create();
        $this->assertTrue(Credential::query()->visibleTo($admin)->search('qzadm', $admin)->whereKey($k->id)->exists());

        /* Kayıt bazlı parola izni olan rol kullanıcı adında arayabilir. */
        $rol = $this->ozelRol();
        $izinli = User::factory()->create(['role_id' => $rol->id]);
        $this->ver($izinli, $grup, ['view']);
        $this->rolVer($rol, $k, 'field.password');
        $this->assertTrue(Credential::query()->visibleTo($izinli)->search('qzadm', $izinli)->whereKey($k->id)->exists());
    }

    #[Test]
    public function toplu_cikti_export_yetkisiyle_suzulur(): void
    {
        $user = User::factory()->create();
        $x = CredentialGroup::factory()->create();
        $y = CredentialGroup::factory()->create();
        $this->ver($user, $x, ['view', 'field.password', 'export']);
        $this->ver($user, $y, ['view', 'field.password']); // parolayı görür, dosyaya çıkaramaz
        $kx = Credential::factory()->for($x, 'group')->create(['password' => 'PW-X']);
        $ky = Credential::factory()->for($y, 'group')->create(['password' => 'PW-Y']);

        $sonuc = app(PasswordOutput::class)->uret(collect([$kx->load('group'), $ky->load('group')]), $user, 'deneme');

        $this->assertSame(1, $sonuc['yazilan']);
        $this->assertSame(1, $sonuc['yetkisiz']);
        /* Çıktı tek denetim satırıdır; dosyaya giren kayıtlar ayrıntısındadır. */
        $this->assertDatabaseHas('parola_ciktisi_satirlari', ['credential_id' => $kx->id]);
        $this->assertDatabaseMissing('parola_ciktisi_satirlari', ['credential_id' => $ky->id]);
    }

    /** @return array{0: string, 1: Credential} */
    private function jetonlu(array $yetenekler): array
    {
        $grup = CredentialGroup::factory()->create();
        $k = Credential::factory()->for($grup, 'group')->create(['host' => '10.0.0.5', 'port' => 22, 'username' => 'root']);
        $sahip = User::factory()->create();
        $this->ver($sahip, $grup, ['view', 'field.host', 'field.port', 'field.url', 'field.password']);

        $yeni = ApiToken::mint();
        ApiToken::create([
            'name' => 'T', 'user_id' => $sahip->id, 'token_hash' => $yeni['hash'], 'prefix' => $yeni['prefix'],
            'abilities' => [CredentialPolicy::RESOURCE => $yetenekler],
        ]);

        return [$yeni['plain'], $k];
    }

    #[Test]
    public function jetonun_yetenek_listesi_tek_kayit_ucuna_da_uygulanir(): void
    {
        [$duz, $k] = $this->jetonlu(['rotate']);

        $this->withToken($duz)->getJson('/api/v1/records')->assertForbidden();
        $this->withToken($duz)->getJson("/api/v1/records/{$k->uuid}")->assertForbidden();
    }

    #[Test]
    public function jetonun_alan_yetenegi_olmayan_alan_donmez(): void
    {
        [$duz, $k] = $this->jetonlu(['view']);

        foreach (["/api/v1/records/{$k->uuid}", '/api/v1/records'] as $adres) {
            $json = $this->withToken($duz)->getJson($adres)->assertOk()->json();
            $kayit = $json['data'][0] ?? $json['data'];
            $this->assertArrayNotHasKey('adres', $kayit);
            $this->assertArrayNotHasKey('port', $kayit);
            $this->assertArrayNotHasKey('kullanici', $kayit);
            $this->assertSame($k->uuid, $kayit['kimlik']);
        }

    }

    #[Test]
    public function jeton_alan_yeteneklerini_tasiyorsa_alanlar_doner(): void
    {
        [$duz, $k] = $this->jetonlu(['view', 'field.host', 'field.port', 'field.password']);

        $kayit = $this->withToken($duz)->getJson("/api/v1/records/{$k->uuid}")->assertOk()->json('data');
        $this->assertSame('10.0.0.5', $kayit['adres']);
        $this->assertSame(22, $kayit['port']);
        $this->assertSame('root', $kayit['kullanici']);
    }
}
