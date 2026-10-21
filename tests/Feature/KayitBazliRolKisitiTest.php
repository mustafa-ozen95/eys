<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Grubun içinden TEK BİR KAYDI bir role kapatmak.
 *
 * Yetki bu panelde grup düzeyindedir ve çoğu zaman doğru ölçü odur. Ama aynı
 * grupta duran iki kayıttan biri bir role kapalı olmak zorunda kalabiliyor
 * (ör. bir cihazın web yönetim hesabı bir role kapalı, aynı cihazın FTP
 * destek hesabı açık).
 *
 * KISIT HER ŞEYİ KAPSAR: görüntüleme, düzenleme, silme, parola açma, oturum
 * açma. Aksiyona göre ayrım yok.
 *
 * İKİ UYGULAMA NOKTASI var ve ikisi de sınanır: sorgu süzmesi (liste) ve
 * politika (doğrudan adres). Yalnızca listeyi süzmek, adresi bilene kapıyı
 * açık bırakırdı.
 */
class KayitBazliRolKisitiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Credential, 2: Credential, 3: Role} */
    private function sahne(): array
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Ortak Grup']);

        $rol = Role::create([
            'slug' => 'kisitli',
            'label' => 'Kısıtlı',
            'badge' => 'gray',
            'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => [
                'all' => [],
                'groups' => ['Ortak Grup' => ['view', 'update', 'delete', 'field.password', 'session']],
            ]],
        ]);

        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);

        $acik = Credential::factory()->create(['credential_group_id' => $grup->getKey(), 'name' => 'Açık Kayıt']);
        $kapali = Credential::factory()->create(['credential_group_id' => $grup->getKey(), 'name' => 'Kapalı Kayıt']);

        $kapali->restrictedRoles()->attach($rol);

        return [$kullanici, $acik, $kapali, $rol];
    }

    #[Test]
    public function kisitli_kayit_listede_gelmez_ama_ayni_gruptaki_oteki_gelir(): void
    {
        [$kullanici, $acik, $kapali] = $this->sahne();

        $gorulen = Credential::query()->visibleTo($kullanici)->pluck('id');

        $this->assertTrue($gorulen->contains($acik->getKey()), 'Aynı gruptaki öteki kayıt görünmeli.');
        $this->assertFalse($gorulen->contains($kapali->getKey()), 'Kısıtlı kayıt sorguda gelmemeli.');
    }

    /**
     * SORGUYU SÜZMEK YETMEZ: adresi bilen doğrudan kayda gidebilir.
     */
    #[Test]
    public function kisitli_kayitta_hicbir_islem_yapilamaz(): void
    {
        [$kullanici, $acik, $kapali] = $this->sahne();

        foreach (['view', 'update', 'delete', 'reveal', 'session'] as $eylem) {
            $this->assertTrue($kullanici->can($eylem, $acik), "Açık kayıtta {$eylem} yapılabilmeli.");
            $this->assertFalse($kullanici->can($eylem, $kapali), "Kısıtlı kayıtta {$eylem} YAPILAMAMALI.");
        }
    }

    /**
     * GRUP SAYISI DA KISITI GÖRÜR.
     *
     * Kenar çubuğu "srv-a · 3" diyor, kullanıcı açınca bir kayıt buluyordu:
     * sayı yalnızca "etkin mi" diye soruyor, kayıt kısıtını hiç bilmiyordu.
     * Yanlış sayı, gizlenen kaydın VARLIĞINI ele verir — ve kullanıcıya
     * göremediği bir şeyin sayısını söyler.
     */
    #[Test]
    public function grup_sayisi_kisitli_kaydi_saymaz(): void
    {
        [$kullanici, $acik, $kapali] = $this->sahne();

        $grup = CredentialGroup::query()
            ->where('name', 'Ortak Grup')
            ->withCount(['credentials' => fn ($q) => $q->visibleTo($kullanici)])
            ->firstOrFail();

        $this->assertSame(1, $grup->credentials_count, 'Sayı yalnızca görülebilen kaydı saymalı.');
        $this->assertSame(
            Credential::query()->visibleTo($kullanici)->count(),
            $grup->credentials_count,
            'Kenar çubuğundaki sayı, listenin gösterdiğiyle aynı olmalı.',
        );
    }

    /** Kısıt role aittir: başka roldeki kullanıcı etkilenmez. */
    #[Test]
    public function baska_rol_etkilenmez(): void
    {
        [, , $kapali, $rol] = $this->sahne();

        $baskaRol = Role::create([
            'slug' => 'serbest',
            'label' => 'Serbest',
            'badge' => 'gray',
            'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => [
                'all' => ['view'],
                'groups' => [],
            ]],
        ]);

        $baskasi = User::factory()->create(['role_id' => $baskaRol->getKey()]);

        $this->assertTrue($baskasi->can('view', $kapali));
        $this->assertTrue(Credential::query()->visibleTo($baskasi)->pluck('id')->contains($kapali->getKey()));
    }

    /**
     * YÖNETİCİ KISITLANMAZ.
     *
     * Yönetici bütün kontrolleri geçiyor; buradan kısıtlamak kasayı kimsenin
     * açamayacağı bir kutuya çevirebilirdi.
     */
    #[Test]
    public function yonetici_kisitlanmaz(): void
    {
        [, , $kapali, $rol] = $this->sahne();

        $yonetici = User::factory()->admin()->create();
        $kapali->restrictedRoles()->attach($yonetici->role);

        $this->assertTrue($yonetici->can('view', $kapali));
        $this->assertTrue(Credential::query()->visibleTo($yonetici)->pluck('id')->contains($kapali->getKey()));
    }

    /** Kısıt kaldırılınca kayıt geri gelir. */
    #[Test]
    public function kisit_kaldirilinca_geri_gelir(): void
    {
        [$kullanici, , $kapali, $rol] = $this->sahne();

        $kapali->restrictedRoles()->detach($rol);

        $this->assertTrue($kullanici->can('view', $kapali->fresh()));
        $this->assertTrue(Credential::query()->visibleTo($kullanici)->pluck('id')->contains($kapali->getKey()));
    }

    /**
     * FORMDAN YAZILABİLİR — ama yalnızca yönetici yazabilir.
     *
     * Kısıtı koyabilen kişi yetkiyi dağıtan kişidir. Kısıtlanmış bir rol
     * kendi kısıtını kaldırabilseydi kısıt diye bir şey olmazdı. Form o
     * alanı yönetici olmayana zaten basmıyor ama forma güvenmek denetim
     * değildir.
     */
    #[Test]
    public function kisiti_yalnizca_yonetici_yazabilir(): void
    {
        [$kullanici, $acik, , $rol] = $this->sahne();
        $yonetici = User::factory()->admin()->create();

        $alanlar = [
            'group' => $acik->group->uuid,
            'name' => $acik->name,
            'access_type' => $acik->access_type->value,
            'operating_system' => $acik->operating_system->value,
            'reason' => 'kısıt sınaması',
            'restricted_roles' => [$rol->uuid],
        ];

        /* Yönetici yazar. */
        $this->actingAs($yonetici)->put(route('credentials.update', $acik), $alanlar);
        $this->assertTrue($acik->fresh()->restrictedRoles()->whereKey($rol->getKey())->exists());

        /* Kısıtlanan rolün kendisi kaldıramaz: kaydı zaten göremiyor. */
        $this->actingAs($kullanici)
            ->put(route('credentials.update', $acik), [...$alanlar, 'restricted_roles' => []])
            ->assertForbidden();

        $this->assertTrue($acik->fresh()->restrictedRoles()->whereKey($rol->getKey())->exists());
    }

    /**
     * ALANI GÖNDERMEYEN İSTEK KISITI SİLMEZ.
     *
     * Sessizce açılan bir kayıt, kısıtın en kötü biçimde kaybolmasıdır.
     */
    #[Test]
    public function alani_gondermeyen_guncelleme_kisiti_silmez(): void
    {
        [, , $kapali, $rol] = $this->sahne();
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->put(route('credentials.update', $kapali), [
            'group' => $kapali->group->uuid,
            'name' => 'Yeni Ad',
            'access_type' => $kapali->access_type->value,
            'operating_system' => $kapali->operating_system->value,
            'reason' => 'ad değişikliği',
        ]);

        $this->assertSame('Yeni Ad', $kapali->fresh()->name);
        $this->assertTrue($kapali->fresh()->restrictedRoles()->whereKey($rol->getKey())->exists());
    }
}
