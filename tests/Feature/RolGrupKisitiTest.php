<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rol, yalnızca ADI GEÇEN gruplarda yetkilidir.
 *
 * Bir rolü belirli gruplardan uzak tutmanın yolu, görebileceği grupları
 * saymaktır: şablon "hepsi" derse istisna yazılamaz, çünkü şablon yalnızca
 * EKLER (bkz. RolePermissions). Sayarak yazmak aynı zamanda güvenli tarafa
 * düşer — yarın açılan bir grup kendiliğinden görünmez olur, açıkça
 * verilmesi gerekir.
 *
 * Tipik kullanım: bir rolün birkaç hassas grubu hiç görmemesi.
 */
class RolGrupKisitiTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, list<string>> $gruplar */
    private function rol(array $gruplar, string $slug = 'sinirli'): Role
    {
        return Role::create([
            'slug' => $slug,
            'label' => 'Sınırlı',
            'badge' => 'gray',
            'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => $gruplar]],
        ]);
    }

    #[Test]
    public function sayilmayan_grup_gorulmez(): void
    {
        $acik = CredentialGroup::factory()->create(['name' => 'Açık Grup']);
        $kapali = CredentialGroup::factory()->create(['name' => 'Kapalı Grup']);

        $acikKayit = Credential::factory()->create(['credential_group_id' => $acik->getKey()]);
        $kapaliKayit = Credential::factory()->create(['credential_group_id' => $kapali->getKey()]);

        $rol = $this->rol(['Açık Grup' => ['view', 'field.host']]);
        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);

        $this->assertTrue($kullanici->can('view', $acikKayit));
        $this->assertFalse($kullanici->can('view', $kapaliKayit), 'Sayılmayan grup görülmemeli.');

        /*
         * SÜZME VERİTABANINDA olmalı: ekranda gizlemek yetmez, sorgu da
         * getirmemeli. Aksi hâlde adresi bilen kayda ulaşırdı.
         */
        $gorulen = Credential::query()->visibleTo($kullanici)->pluck('id');

        $this->assertTrue($gorulen->contains($acikKayit->getKey()));
        $this->assertFalse($gorulen->contains($kapaliKayit->getKey()));
    }

    /** Şablonu boş olan rol hiçbir şey göremez. */
    #[Test]
    public function bos_sablon_hicbir_sey_gostermez(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Bir Grup']);
        Credential::factory()->create(['credential_group_id' => $grup->getKey()]);

        $kullanici = User::factory()->create(['role_id' => $this->rol([], 'bos')->getKey()]);

        /*
         * Liste EKRANI artık herkese açık (kişisel alan geldi); ölçülecek
         * şey ekranın açılıp açılmadığı değil, İÇİNDE ne göründüğü.
         */
        $this->assertTrue($kullanici->can('viewAny', Credential::class));
        $this->assertSame(0, Credential::query()->visibleTo($kullanici)->count());
    }

    /**
     * ADI BULUNAMAYAN GRUP YETKİ VERMEZ — ve sessiz kalmaz.
     *
     * Grup yeniden adlandırılabilir ya da başka bir kurulumdan yedek
     * dönülebilir. Bu yönde hata güvenli tarafa düşüyor (yetki kaybolur) ama
     * sessiz kalırsa "neden göremiyorum" sorusunun cevabı aranmaz hâle gelir.
     */
    #[Test]
    public function bulunamayan_grup_adi_yetki_vermez_ve_kayda_gecer(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Var Olan']);
        $kayit = Credential::factory()->create(['credential_group_id' => $grup->getKey()]);

        Log::spy();

        $rol = $this->rol(['Artık Yok' => ['view']], 'hayalet');
        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);

        $this->assertFalse($kullanici->can('view', $kayit));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mesaj, array $bag): bool => str_contains($mesaj, 'bulunamadı')
                && ($bag['grup'] ?? null) === 'Artık Yok');
    }

    /** Yönetici şablona bakmaz. */
    #[Test]
    public function yonetici_kisitlanmaz(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Kapalı Grup']);
        $kayit = Credential::factory()->create(['credential_group_id' => $grup->getKey()]);

        $this->assertTrue(User::factory()->admin()->create()->can('view', $kayit));
        $this->assertSame([], app(RolePermissions::class)->map(
            Role::where('is_admin', true)->first(), CredentialPolicy::RESOURCE, 'view',
        ));
    }
}
