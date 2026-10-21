<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Enums\RotationStage;
use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\CredentialListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aynı satıra giden İKİ YOL: ekleme ve düzenleme.
 *
 * Aynı alanı yazan iki ekrandan yalnızca birinde doğrulama olursa diğeri
 * açık kapı olur. Bu yüzden her kural İKİ YOLDA DA sınanır.
 */
class CredentialWriteTest extends TestCase
{
    use RefreshDatabase;

    private function yetkiliKullanici(): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, [
            'view', 'create', 'update', 'delete',
            'field.host', 'field.port', 'field.url', 'field.username', 'field.password', 'field.notes',
        ]);

        return [$user, $group];
    }

    /**
     * Kaydı düzenleyebilen ama alanlarını GÖREMEYEN kullanıcı.
     *
     * @return array{0: User, 1: CredentialGroup}
     */
    private function alanYetkisizKullanici(): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'create', 'update']);

        return [$user, $group];
    }

    /** @return array<string, mixed> */
    private function gecerliVeri(CredentialGroup $group, array $ustune = []): array
    {
        return [
            'group' => $group->uuid,
            'name' => 'Yeni kayit',
            'access_type' => 'ssh',
            'operating_system' => 'linux',
            'host' => '10.0.0.5',
            'username' => 'root',
            'password' => 'ilk-parola',
            'is_active' => '1',
            'position' => 0,
            ...$ustune,
        ];
    }

    #[Test]
    public function okuyucu_kayit_ekleyemez_ve_duzenleyemez(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $credential = Credential::factory()->for($group, 'group')->create();

        /* Hiçbir grupta 'create' yok: kapı reddeder, hiçbir şey yazılmaz. */
        $this->actingAs($user)->post(route('credentials.store'), $this->gecerliVeri($group))
            ->assertForbidden();

        $this->assertSame(1, Credential::query()->count(), 'Yeni kayıt yazılmamalı.');

        /* Düzenleme kapısı değişmedi: 'update' yetkisi yok, 403. */
        $this->actingAs($user)
            ->put(route('credentials.update', $credential), $this->gecerliVeri($group))
            ->assertForbidden();
    }

    /**
     * Form artık ayrı sayfa değil, listenin üstünde açılan pencere. Sayfa
     * korumasıyla birlikte KAYBOLABİLECEK tek şey buydu: yetkisi olmayana
     * pencere hiç basılmamalı — "bas ama gizle" güvenlik değildir.
     */
    #[Test]
    public function okuyucunun_ekraninda_form_penceresi_hic_basilmaz(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $credential = Credential::factory()->for($group, 'group')->create();

        /* DÜZENLEME penceresi basılmaz: o kayıtta 'update' yetkisi yok. */
        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertDontSee('id="kayit-duzenle"', false)
            ->assertDontSee('data-modal-acik', false);

        /* Ekleme penceresi de basılmaz: yazabileceği grup yok. */
        $this->actingAs($user)
            ->get(route('credentials.index', ['grup' => $group->uuid, 'pencere' => 'ekle']))
            ->assertOk()
            ->assertDontSee('id="kayit-ekle"', false);

        /*
         * Seçenek listesi BOŞ — Kişisel alan kaldırıldı, okuyucu hiçbir gruba
         * yazamaz.
         */
        $secenekler = app(CredentialListing::class)
            ->forRequest(tap(Request::create('/erisim'),
                fn ($r) => $r->setUserResolver(fn () => $user)))['groupOptions'];

        $this->assertSame([], $secenekler);
        $this->assertFalse(CredentialGroup::query()->where('is_personal', true)->exists(), 'taşıyıcı grup silindi');
    }

    #[Test]
    public function yetkili_kullanicinin_penceresi_adresle_acik_gelir(): void
    {
        [$user, $group] = $this->yetkiliKullanici();
        $credential = Credential::factory()->for($group, 'group')->create();

        $this->actingAs($user)
            ->get(route('credentials.index', ['pencere' => 'ekle']))
            ->assertOk()
            ->assertSee('id="kayit-ekle"', false)
            ->assertSee('data-modal-acik', false);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertSee('id="kayit-duzenle"', false)
            ->assertSee('data-modal-acik', false);
    }

    /**
     * Düzenleme penceresindeki grup kutusu, kaydın KENDİ grubunu içermeli.
     * Kutu "ekleyebildiğim gruplar" ile doldurulsaydı, güncelleme yetkisi
     * olup ekleme yetkisi olmayan biri kaydettiğinde kayıt sessizce başka
     * gruba taşınırdı.
     */
    #[Test]
    public function duzenleme_penceresi_kaydin_kendi_grubunu_gosterir(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create(['name' => 'Yalnizca guncellenebilir']);
        $this->yetkilendir($user, $group, ['view', 'update']);

        $credential = Credential::factory()->for($group, 'group')->create();

        $html = $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$group->uuid.'"\s+selected>/',
            $html,
            'Düzenleme penceresindeki grup kutusu kaydın kendi grubunu seçili göstermeli.',
        );
    }

    /**
     * Görülemeyen alan formda BASILMAZ.
     *
     * Form ayrı sayfadayken de böyleydi ama kimse yan yana görmüyordu:
     * detay panelinde "•••" yazarken aynı değer formda açık açık duruyordu.
     */
    #[Test]
    public function goremedigi_alan_duzenleme_penceresinde_basilmaz(): void
    {
        [$user, $group] = $this->alanYetkisizKullanici();

        $credential = Credential::factory()->for($group, 'group')->create([
            'notes' => 'GIZLI-NOT-42',
            'host' => '10.9.9.9',
            'username' => 'gizli-kullanici',
        ]);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertSee('id="kayit-duzenle"', false)
            ->assertDontSee('GIZLI-NOT-42')
            ->assertDontSee('10.9.9.9')
            ->assertDontSee('gizli-kullanici');
    }

    /**
     * Görülemeyen alan DEĞİŞTİRİLEMEZ de.
     *
     * Devre dışı kutu tarayıcıda açılabilir, istek elle kurulabilir: asıl
     * koruma sunucudadır. Parolada bu bir okuma değil ELE GEÇİRME kısıtıdır —
     * parolayı göremeyen biri onu kendi bildiği bir değerle değiştirebilseydi
     * görme yetkisinin anlamı kalmazdı.
     */
    #[Test]
    public function goremedigi_alani_elle_gonderse_de_degistiremez(): void
    {
        [$user, $group] = $this->alanYetkisizKullanici();

        $credential = Credential::factory()->for($group, 'group')->create([
            'name' => 'Eski ad',
            'notes' => 'GIZLI-NOT-42',
            'host' => '10.9.9.9',
            'username' => 'gizli-kullanici',
            'password' => 'eski-parola',
        ]);

        $this->actingAs($user)
            ->put(route('credentials.update', $credential), $this->gecerliVeri($group, [
                'name' => 'Yeni ad',
                'notes' => 'ustune-yazdim',
                'host' => '10.0.0.1',
                'username' => 'yeni-kullanici',
                'password' => 'benim-bildigim-parola',
            ]))
            ->assertSessionHasNoErrors();

        $taze = $credential->fresh();

        $this->assertSame('Yeni ad', $taze->name, 'Görebildiği alanı değiştirebilmeli.');
        $this->assertSame('GIZLI-NOT-42', $taze->notes);
        $this->assertSame('10.9.9.9', $taze->host);
        $this->assertSame('gizli-kullanici', $taze->username);
        $this->assertSame('eski-parola', $taze->password);
    }

    #[Test]
    public function alan_yetkisi_olan_ayni_alanlari_degistirebilir(): void
    {
        [$user, $group] = $this->yetkiliKullanici();

        $credential = Credential::factory()->for($group, 'group')->create([
            'notes' => 'eski not',
            'host' => '10.9.9.9',
        ]);

        $this->actingAs($user)
            ->put(route('credentials.update', $credential), $this->gecerliVeri($group, [
                'notes' => 'yeni not',
                'host' => '10.0.0.1',
            ]))
            ->assertSessionHasNoErrors();

        $taze = $credential->fresh();

        $this->assertSame('yeni not', $taze->notes);
        $this->assertSame('10.0.0.1', $taze->host);
    }

    /**
     * Detayda YALNIZCA son tur durur.
     *
     * Beş satır listelemek paneli geçmiş dökümüne çeviriyordu; geçmişin yeri
     * kayıtlar ekranı. Oraya, o kayda süzülmüş bir bağlantı verilir.
     */
    #[Test]
    public function detayda_yalnizca_son_tur_ve_tumu_baglantisi_gorunur(): void
    {
        [$user, $group] = $this->yetkiliKullanici();
        $this->yetkilendir($user, $group, ['rotate']);

        $credential = Credential::factory()->for($group, 'group')->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.9',
            'username' => 'root',
            'password' => 'parola',
            'rotation_enabled' => true,
        ]);

        foreach ([3, 60, 2880] as $dakika) {
            $credential->rotations()->create([
                'actor_name' => 'deneme',
                'status' => RotationStatus::Succeeded,
                'stage' => RotationStage::RecoveryRemoved,
            ])->forceFill(['created_at' => now()->subMinutes($dakika)])->save();
        }

        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $credential->uuid]))
            ->assertOk()
            ->getContent();

        /* Üç tur var ama detayda bir satır: en yenisi. */
        $bolum = mb_substr($html, (int) mb_strpos($html, 'Parola güncelleme'));

        $this->assertSame(
            1,
            preg_match_all('/facts__row/', mb_substr($bolum, 0, (int) mb_strpos($bolum, 'detail__foot'))),
            'Detayda yalnızca son tur listelenmeli.',
        );

        $this->assertStringContainsString(
            route('logs.index', ['kayit' => $credential->uuid]),
            $html,
            'Geçmişin tamamına giden bağlantı olmalı.',
        );
    }

    #[Test]
    public function yetkisiz_grup_kimligi_forma_elle_yazilamaz_ekleme(): void
    {
        [$user, $group] = $this->yetkiliKullanici();
        $baskaGrup = CredentialGroup::factory()->create();

        $this->actingAs($user)
            ->post(route('credentials.store'), $this->gecerliVeri($group, ['group' => $baskaGrup->uuid]))
            ->assertSessionHasErrors('group');

        $this->assertDatabaseCount('credentials', 0);
    }

    #[Test]
    public function yetkisiz_grup_kimligi_forma_elle_yazilamaz_duzenleme(): void
    {
        [$user, $group] = $this->yetkiliKullanici();
        $baskaGrup = CredentialGroup::factory()->create();
        $credential = Credential::factory()->for($group, 'group')->create();

        $this->actingAs($user)
            ->put(route('credentials.update', $credential), $this->gecerliVeri($group, ['group' => $baskaGrup->uuid]))
            ->assertSessionHasErrors('group');

        $this->assertSame($group->id, $credential->fresh()->credential_group_id);
    }

    #[Test]
    public function kayit_eklenir_ve_kayda_gecer(): void
    {
        [$user, $group] = $this->yetkiliKullanici();

        $this->actingAs($user)
            ->post(route('credentials.store'), $this->gecerliVeri($group))
            ->assertSessionHasNoErrors();

        $credential = Credential::firstOrFail();

        $this->assertSame('Yeni kayit', $credential->name);
        $this->assertSame('ilk-parola', $credential->password);
        $this->assertNull(
            $credential->port,
            'Port boş bırakıldıysa boş kalmalı: port standart dışı olabilir, varsayılan yazmak yanlış bilgi olur.',
        );
        $this->assertDatabaseHas('activity_logs', ['action' => 'created']);
    }

    #[Test]
    public function bos_birakilan_parola_alani_parolayi_silmez(): void
    {
        [$user, $group] = $this->yetkiliKullanici();
        $credential = Credential::factory()->for($group, 'group')->create(['password' => 'eski-parola']);

        $this->actingAs($user)
            ->put(route('credentials.update', $credential), $this->gecerliVeri($group, ['password' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('eski-parola', $credential->fresh()->password);
    }

    /**
     * Gerekçe ARTIK YALNIZCA "kritik" işaretli kayıtta zorunlu.
     *
     * İşaretsiz kaydın gerekçesiz silinebildiği ayrıca sınanıyor
     * (ReasonAskedOnCriticalRecordTest).
     */
    #[Test]
    public function kritik_kaydin_silinmesi_gerekce_ister(): void
    {
        [$user, $group] = $this->yetkiliKullanici();
        $credential = Credential::factory()->for($group, 'group')->create(['is_critical' => true]);

        $this->actingAs($user)
            ->delete(route('credentials.destroy', $credential))
            ->assertSessionHasErrors('reason');

        $this->assertModelExists($credential);

        $this->actingAs($user)
            ->delete(route('credentials.destroy', $credential), ['reason' => 'Sunucu kapatildi'])
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($credential);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deleted',
            'reason' => 'Sunucu kapatildi',
        ]);
    }

    #[Test]
    public function url_ve_port_dogrulanir(): void
    {
        [$user, $group] = $this->yetkiliKullanici();

        $this->actingAs($user)
            ->post(route('credentials.store'), $this->gecerliVeri($group, [
                'url' => 'javascript:alert(1)',
                'port' => 70000,
            ]))
            ->assertSessionHasErrors(['url', 'port']);
    }

    /**
     * Kullanıcıya bir grupta yetki verir.
     *
     * Testler bunu tek yerden çağırır: izin satırının nasıl yazıldığı
     * değişirse tek dosyada değişsin.
     *
     * @param  list<string>  $actions
     */
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
}
