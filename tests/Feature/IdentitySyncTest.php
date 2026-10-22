<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Merkezdeki değişikliğin buraya taşınması.
 *
 * NEDEN VAR: merkezde rolü değişen ya da hesabı askıya alınan kişi, oturumu
 * açık olduğu için burada erişmeye devam ediyordu. Girişte bakmak yetmez;
 * değişiklik, kişi hiç giriş yapmadan da olur.
 *
 * Testlerin kilitlediği asıl şey, KAPATMANIN SINIRI: merkez yanıt vermediğinde
 * kimse atılmaz, yanıtta anılmayan kimliğe dokunulmaz ve yöneticilik
 * kendiliğinden devrolmaz.
 */
class IdentitySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.test',
            'identity.client_id' => 'istemci-1',
            'identity.client_secret' => 'sir-1',
            'identity.verify_minutes' => 5,
        ]);

        Cache::flush();
    }

    private function bagli(array $ustune = []): User
    {
        $kullanici = User::factory()->create($ustune);

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => 'kimlik',
            'subject' => 'merkez-'.$kullanici->id,
        ]);

        return $kullanici;
    }

    private function merkezSoyler(User $kullanici, array $durum): void
    {
        Http::fake([
            'merkez.test/api/identity-status' => Http::response([
                'identities' => ['merkez-'.$kullanici->id => $durum],
            ]),
        ]);
    }

    public function test_merkezde_askiya_alinan_hesap_kapatilir(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['status' => 'askida', 'can_enter' => false, 'role' => 'network']);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertSame(UserStatus::Passive, $kullanici->fresh()->status);
    }

    /** Merkezde silinen kimlik de erişimini kaybeder; hesap SİLİNMEZ, kapatılır. */
    public function test_merkezde_silinen_kimlik_kapatilir(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['status' => 'missing', 'role' => null]);

        $this->artisan('identity:sync')->assertSuccessful();

        $taze = $kullanici->fresh();

        $this->assertNotNull($taze, 'Hesap silinmemeli: denetim kaydı sahipsiz kalmamalı.');
        $this->assertSame(UserStatus::Passive, $taze->status);
    }

    /**
     * Merkezde rol değişince YETKİLER DE onunla gelir.
     *
     * Rol canlı okunuyor: kullanıcıya kopyalanmadığı için ayrıca bir şey
     * uygulamak gerekmez — rolü değişen kişi yeni rolün yetkileriyle çalışır.
     */
    public function test_rol_degisince_yeni_rolun_yetkileri_gecerli_olur(): void
    {
        CredentialGroup::factory()->create(['name' => 'Grup A']);

        $teknik = Role::query()->updateOrCreate(['slug' => 'teknik'], ['label' => 'Teknik']);
        $network = Role::query()->updateOrCreate(['slug' => 'network'], [
            'label' => 'Network',
            'template' => ['credential_group' => ['all' => ['view'], 'groups' => []]],
        ]);

        $kullanici = $this->bagli(['role_id' => $teknik->id]);

        $this->merkezSoyler($kullanici, ['status' => 'etkin', 'can_enter' => true, 'role' => 'network']);

        $this->artisan('identity:sync')->assertSuccessful();

        $taze = $kullanici->fresh();
        $kayit = Credential::factory()
            ->for(CredentialGroup::query()->firstWhere('name', 'Grup A'), 'group')
            ->create();

        $this->assertSame($network->id, $taze->role_id);
        $this->assertSame(0, $taze->permissions()->count(), 'Yetki kopyalanmamalı.');
        $this->assertTrue($taze->can('view', $kayit), 'Yeni rolün yetkisi geçerli olmalı.');
    }

    /** Merkezdeki yöneticilik kendiliğinden devrolmaz. */
    public function test_yoneticilik_devrolmaz(): void
    {
        Role::query()->updateOrCreate(['slug' => 'admin'], ['label' => 'Yönetici', 'is_admin' => true]);
        $teknik = Role::query()->updateOrCreate(['slug' => 'teknik'], ['label' => 'Teknik']);

        $kullanici = $this->bagli(['role_id' => $teknik->id]);

        $this->merkezSoyler($kullanici, ['status' => 'etkin', 'can_enter' => true, 'role' => 'admin']);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertSame($teknik->id, $kullanici->fresh()->role_id);
        $this->assertFalse($kullanici->fresh()->isAdmin());
    }

    /**
     * YANIT GELMEYEN KİMLİĞE DOKUNULMAZ.
     *
     * "Bilmiyorum"u "yok" saymak, merkezdeki bir aksaklıkta bütün kadroyu
     * panelden atmak olurdu.
     */
    public function test_yanitta_anilmayan_kimlige_dokunulmaz(): void
    {
        $kullanici = $this->bagli();

        Http::fake(['merkez.test/api/identity-status' => Http::response(['identities' => []])]);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertTrue($kullanici->fresh()->isActive());
    }

    /**
     * ERİŞİMİ KALKAN KİŞİ İSTEĞİN ORTASINDA DIŞARI ALINIR.
     *
     * Bir sonraki girişi beklemek, kapatılmış bir hesabın oturumunu açık
     * bırakmak olurdu.
     */
    public function test_erisimi_kalkan_kisi_oturumdan_atilir(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['status' => 'askida', 'can_enter' => false]);

        $this->actingAs($kullanici)
            ->get(route('account.profile'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(UserStatus::Passive, $kullanici->fresh()->status);
    }

    /** Merkez erişilemezse KİMSE ATILMAZ: panelin kendi girişi merkezden bağımsızdır. */
    public function test_merkez_ulasilamazsa_kimse_atilmaz(): void
    {
        $kullanici = $this->bagli();

        Http::fake(['merkez.test/*' => Http::response('', 500)]);

        $this->actingAs($kullanici)->get(route('account.profile'))->assertOk();

        $this->assertTrue($kullanici->fresh()->isActive());
        $this->assertAuthenticatedAs($kullanici);
    }

    /** Merkeze HER İSTEKTE sorulmaz; yanıt kullanıcı başına önbelleklenir. */
    public function test_merkeze_her_istekte_sorulmaz(): void
    {
        $kullanici = $this->bagli();
        $this->merkezSoyler($kullanici, ['status' => 'etkin', 'can_enter' => true, 'role' => null]);

        $this->actingAs($kullanici)->get(route('account.profile'))->assertOk();
        $this->actingAs($kullanici)->get(route('account.profile'))->assertOk();

        Http::assertSentCount(1);
    }
}
