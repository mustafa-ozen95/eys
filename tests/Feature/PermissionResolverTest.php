<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Yetki kararının sırası.
 *
 * İKİ KATMAN VAR: rol (canlı) ve kişi (istisna). Buradaki testlerin çoğu
 * KİŞİ katmanını yalıtarak sınar — kullanıcı, hiçbir şey vermeyen bir rolde
 * açılır — ve sonundaki bölüm iki katmanın birbirine göre sırasını kilitler.
 *
 * Panelin tamamı bu sıraya dayanıyor ama tek bir testi yoktu: "hepsinde
 * görsün, biri hariç" kuralının çalıştığını hiçbir şey kanıtlamıyordu.
 * Kararlar istek boyunca bir tabloda tutuluyor (hız için); bu testler hem
 * sırayı hem de tablonun bayatlamadığını kilitler.
 */
class PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private const RESOURCE = CredentialPolicy::RESOURCE;

    private function resolver(): UserPermissionResolver
    {
        return app(UserPermissionResolver::class);
    }

    /**
     * Rolü HİÇBİR ŞEY VERMEYEN kullanıcı.
     *
     * Kişi katmanını yalıtmak için: rol canlı okunduğundan, şablonu dolu bir
     * rolde açılan kullanıcı zaten yetkili olurdu ve "eşleşme yoksa kapalı"
     * gibi sorular ölçülemezdi. Rol katmanının kendi testleri aşağıda.
     */
    private function kullanici(): User
    {
        $user = User::factory()->create();
        $user->role->update(['template' => []]);

        return $user->fresh();
    }

    private function izin(User $user, int $recordId, string $action, PermissionEffect $effect): void
    {
        $user->permissions()->create([
            'resource' => self::RESOURCE,
            'resource_id' => $recordId,
            'action' => $action,
            'effect' => $effect,
        ]);

        $this->resolver()->flush($user);
    }

    #[Test]
    public function eslesme_yoksa_varsayilan_kapalidir(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create();

        $this->assertFalse($this->resolver()->allows($user, self::RESOURCE, 'view', $group->getKey()));
    }

    #[Test]
    public function genel_izin_butun_gruplarda_gecerlidir(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'view', $group->getKey()));

        /* Sonradan açılan grup da kurala kendiliğinden uyar. */
        $yeni = CredentialGroup::factory()->create();

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'view', $yeni->getKey()));
    }

    /** "Hepsinde görsün, biri hariç": kayda özel yasak genel izni KESER. */
    #[Test]
    public function kayda_ozel_yasak_genel_izni_keser(): void
    {
        $user = $this->kullanici();
        $acik = CredentialGroup::factory()->create();
        $kapali = CredentialGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);
        $this->izin($user, $kapali->getKey(), 'view', PermissionEffect::Deny);

        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($user, self::RESOURCE, 'view', $acik->getKey()));
        $this->assertFalse($resolver->allows($user, self::RESOURCE, 'view', $kapali->getKey()));
    }

    #[Test]
    public function kayda_ozel_izin_genel_yasagi_asar(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Deny);
        $this->izin($user, $group->getKey(), 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'view', $group->getKey()));
    }

    /** Alan izni 'view' iznini KAPSAMAZ, ona eklenir. */
    #[Test]
    public function alan_izni_view_olmadan_ise_yaramaz(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create();

        $this->izin($user, $group->getKey(), 'field.password', PermissionEffect::Allow);

        $this->assertFalse($this->resolver()->allows($user, self::RESOURCE, 'field.password', $group->getKey()));

        $this->izin($user, $group->getKey(), 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user, self::RESOURCE, 'field.password', $group->getKey()));
    }

    #[Test]
    public function sorgu_daraltmasi_kayda_ozel_yasagi_uygular(): void
    {
        $user = $this->kullanici();
        $acik = CredentialGroup::factory()->create();
        $kapali = CredentialGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);
        $this->izin($user, $kapali->getKey(), 'view', PermissionEffect::Deny);

        Credential::factory()->for($acik, 'group')->create(['name' => 'Gorunur']);
        Credential::factory()->for($kapali, 'group')->create(['name' => 'Gizli']);

        $adlar = Credential::query()->visibleTo($user)->pluck('name')->all();

        $this->assertSame(['Gorunur'], $adlar);
    }

    #[Test]
    public function pasif_kullanici_hicbir_seye_erisemez(): void
    {
        $user = User::factory()->passive()->create();
        $group = CredentialGroup::factory()->create();

        $this->izin($user, UserPermission::ALL_RECORDS, 'view', PermissionEffect::Allow);

        $resolver = $this->resolver();

        $this->assertFalse($resolver->allows($user, self::RESOURCE, 'view', $group->getKey()));
        $this->assertFalse($resolver->allowsAny($user, self::RESOURCE, 'view'));
    }

    /**
     * Karar tablosu AYNI İSTEK içinde bayatlamamalı.
     *
     * Kararlar hız için tabloda tutuluyor. İzin ekranı önce mevcut durumu
     * sorup sonra yazıyor; tablo düşmeseydi kullanıcı yeni yetkiyi kendi
     * yaptığı işlemden sonra göremezdi.
     */
    #[Test]
    public function izin_degisikligi_ayni_istekte_gorunur(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create();
        $resolver = $this->resolver();

        $this->assertFalse($resolver->allows($user, self::RESOURCE, 'view', $group->getKey()));

        $resolver->syncResource($user, self::RESOURCE, [], ['view' => [$group->getKey()]]);

        $this->assertTrue(
            $resolver->allows($user, self::RESOURCE, 'view', $group->getKey()),
            'syncResource sonrası karar tablosu tazelenmeli.',
        );

        $resolver->syncResource($user, self::RESOURCE, [], []);

        $this->assertFalse(
            $resolver->allows($user, self::RESOURCE, 'view', $group->getKey()),
            'Kaldırılan yetki aynı istekte de kalkmalı.',
        );
    }

    #[Test]
    public function ayri_kullanicilarin_kararlari_birbirine_karismaz(): void
    {
        $yetkili = $this->kullanici();
        $yetkisiz = $this->kullanici();
        $group = CredentialGroup::factory()->create();

        $this->izin($yetkili, $group->getKey(), 'view', PermissionEffect::Allow);

        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($yetkili, self::RESOURCE, 'view', $group->getKey()));
        $this->assertFalse($resolver->allows($yetkisiz, self::RESOURCE, 'view', $group->getKey()));
    }

    /* ── Rol katmanı ── */

    /**
     * ROL CANLI OKUNUR: kullanıcıya kopyalanmaz.
     *
     * Kopya eskirdi: rolü düzenleyen ekranda yeni yetkiyi görür, kullanıcılar
     * eski yetkiyle çalışırdı.
     */
    #[Test]
    public function rolun_yetkisi_kullaniciya_gecer(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create(['name' => 'Grup A']);

        $user->role->update(['template' => [
            self::RESOURCE => ['all' => ['view'], 'groups' => []],
        ]]);
        $this->resolver()->flush($user->fresh());

        $this->assertTrue($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $group->getKey()));
    }

    /** Rol düzenlenince kullanıcı için ANINDA geçerli olur. */
    #[Test]
    public function rol_degisince_yetki_hemen_degisir(): void
    {
        $user = $this->kullanici();
        $group = CredentialGroup::factory()->create();

        $user->role->update(['template' => [self::RESOURCE => ['all' => ['view'], 'groups' => []]]]);
        $this->resolver()->flush($user->fresh());
        $this->assertTrue($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $group->getKey()));

        $user->role->update(['template' => []]);
        $this->resolver()->flush($user->fresh());
        $this->assertFalse($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $group->getKey()));
    }

    /** Rol yalnızca ADI GEÇEN grupta yetki verebilir. */
    #[Test]
    public function rol_grup_bazinda_yetki_verebilir(): void
    {
        $user = $this->kullanici();
        $grupA = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $grupB = CredentialGroup::factory()->create(['name' => 'Grup B']);

        $user->role->update(['template' => [
            self::RESOURCE => ['all' => [], 'groups' => ['Grup A' => ['view']]],
        ]]);
        $this->resolver()->flush($user->fresh());

        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupA->getKey()));
        $this->assertFalse($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupB->getKey()));
    }

    /**
     * KİŞİNİN YASAĞI ROLÜ KESER.
     *
     * "Bu kişide olmasın" demenin yolu yoksa istisna diye bir şey yoktur.
     */
    #[Test]
    public function kisisel_yasak_rolun_verdigini_keser(): void
    {
        $user = $this->kullanici();
        $grupA = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $grupB = CredentialGroup::factory()->create(['name' => 'Grup B']);

        $user->role->update(['template' => [self::RESOURCE => ['all' => ['view'], 'groups' => []]]]);
        $this->izin($user, $grupA->getKey(), 'view', PermissionEffect::Deny);

        $resolver = $this->resolver();

        $this->assertFalse($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupA->getKey()));
        $this->assertTrue($resolver->allows($user->fresh(), self::RESOURCE, 'view', $grupB->getKey()));
    }

    /** Kişisel izin, rolün vermediğini ekler. */
    #[Test]
    public function kisisel_izin_rolun_vermedigini_ekler(): void
    {
        $user = $this->kullanici();
        $grupA = CredentialGroup::factory()->create(['name' => 'Grup A']);

        $this->izin($user, $grupA->getKey(), 'view', PermissionEffect::Allow);

        $this->assertTrue($this->resolver()->allows($user->fresh(), self::RESOURCE, 'view', $grupA->getKey()));
    }

    /**
     * SÜZME VERİTABANINDA: rolün verdiği kayıtlar sorguda da görünür,
     * kişinin yasakladıkları görünmez.
     */
    #[Test]
    public function sorgu_iki_katmani_birden_uygular(): void
    {
        $user = $this->kullanici();
        $grupA = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $grupB = CredentialGroup::factory()->create(['name' => 'Grup B']);

        Credential::factory()->for($grupA, 'group')->create(['name' => 'srv-a SSH']);
        Credential::factory()->for($grupB, 'group')->create(['name' => 'Grup B kaydı']);

        $user->role->update(['template' => [self::RESOURCE => ['all' => ['view'], 'groups' => []]]]);
        $this->izin($user, $grupB->getKey(), 'view', PermissionEffect::Deny);

        $adlar = Credential::query()->visibleTo($user->fresh())->pluck('name')->all();

        $this->assertSame(['srv-a SSH'], $adlar);
    }
}
