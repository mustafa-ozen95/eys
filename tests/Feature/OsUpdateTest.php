<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\ActivityAction;
use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Jobs\ApplyOsUpdatesJob;
use App\Jobs\CheckOsUpdatesJob;
use App\Jobs\RebootOsJob;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\OsUpdateState;
use App\Models\Role;
use App\Models\SubGroup;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\OsUpdate\OsRebooter;
use App\Services\OsUpdate\OsUpdateLauncher;
use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateRound;
use App\Services\OsUpdate\OsUpdateScripts;
use App\Services\OsUpdate\OsUpdateShell;
use App\Services\OsUpdate\OsUpdateTargets;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Linux sistem güncellemeleri.
 *
 * Kilitlenen üç şey:
 *   - KİLİT her yoldan geçerlidir: toplu seçim, kardeş kayıt, kuyrukta
 *     beklerken konan kilit.
 *   - Kurmak ve kilitlemek AYRI yetkilerdir; biri ötekini kapsamaz.
 *   - Zamanlayıcı yalnızca DENETLER; kapalıyken hiçbir makineye bağlanmaz.
 *
 * SSH taşıması sahtedir; hiçbir makineye bağlanılmaz.
 */
class OsUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> sahte kabuğa gelen betikler */
    private array $calisan = [];

    /** @var list<int> sahte kabuğun bağlandığı kayıtlar (kimlik) */
    private array $baglanan = [];

    /** Sahte kurulumun çıktısı; null ise başarılı. */
    public ?string $kurulumCiktisi = null;

    /** Doluysa sahte kurulum bağlanamaz (bağlantı/kimlik hatası). */
    public ?string $kurulumBaglantiHatasi = null;

    public ?string $yenidenBaslatmaHatasi = null;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        $this->app->instance(OsUpdateShell::class, new class($test) extends OsUpdateShell
        {
            public function __construct(private OsUpdateTest $test) {}

            public function calistir(Credential $credential, string $betik, int $sure, string $hata): string
            {
                return $this->test->sahteCalistir($betik, $credential);
            }
        });
    }

    public function sahteCalistir(string $betik, ?Credential $kayit = null): string
    {
        $this->calisan[] = $betik;
        $this->baglanan[] = (int) $kayit?->getKey();

        if ($this->yenidenBaslatmaHatasi !== null && $betik === OsUpdateScripts::yenidenBaslatma()) {
            throw new RotationException($this->yenidenBaslatmaHatasi);
        }

        if ($this->kurulumBaglantiHatasi !== null && str_contains($betik, 'setsid')) {
            throw new RotationException($this->kurulumBaglantiHatasi);
        }

        return str_contains($betik, 'setsid')
            ? ($this->kurulumCiktisi ?? "EYS-SONUC:0\n")
            : "EYS-OS:Ubuntu 24.04.3 LTS\nEYS-PM:apt\nEYS-BOLUM:liste\nopenssl/noble-security 3.1 amd64 [upgradable from: 3.0]\n"
                ."vim/noble-updates 9.1 amd64 [upgradable from: 9.0]\nEYS-BOLUM:son\nEYS-REBOOT\n";
    }

    /** @param  list<string>  $actions */
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

    private function makine(CredentialGroup $group, string $name, string $host, array $ek = []): Credential
    {
        return Credential::factory()->for($group, 'group')->create([
            'name' => $name,
            'access_type' => AccessType::Ssh,
            'operating_system' => OperatingSystem::Linux,
            'host' => $host,
            'port' => 22,
            'username' => 'dgn',
            'password' => 'gizli-parola',
            'is_active' => true,
            ...$ek,
        ]);
    }

    /** @return array{0: User, 1: CredentialGroup} */
    private function kurucu(array $ekYetki = []): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'os_update', ...$ekYetki]);

        return [$user, $group];
    }

    /** Satırlar gruplarına göre ayrılır; gruplar Gruplar ekranındaki sırayla gelir. */
    #[Test]
    public function satirlar_grup_grup_ayrilir_grup_sirasiyla(): void
    {
        $user = User::factory()->create();
        $ikinci = CredentialGroup::factory()->create(['name' => 'Teknik PCler', 'position' => 2]);
        $birinci = CredentialGroup::factory()->create(['name' => 'Sunucular', 'position' => 1]);
        $this->yetkilendir($user, $ikinci, ['view', 'os_update']);
        $this->yetkilendir($user, $birinci, ['view', 'os_update']);
        $this->makine($ikinci, 'PC-1', '10.0.9.1');
        $this->makine($ikinci, 'PC-2', '10.0.9.2');
        $this->makine($birinci, 'Sunucu-1', '10.0.9.3');

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertOk()
            /* Solda gruplar sırayla, ortada (Hepsi) satırlar grup başlıkları altında. */
            ->assertSeeInOrder(['Hepsi', 'Sunucular', 'Teknik PCler', 'Sunucular', 'Sunucu-1', 'Teknik PCler', 'PC-1', 'PC-2']);

        /* Grup seçilince yalnızca o grubun makineleri. */
        $this->actingAs($user)->get(route('os-updates.index', ['grup' => $ikinci->uuid]))
            ->assertOk()
            ->assertSee('PC-1')
            ->assertDontSee('Sunucu-1');
    }

    /** Sol sütunda grup sırası sürüklenir; yalnızca grupları sıralayabilene (Gruplar ekranıyla aynı uç). */
    #[Test]
    public function grup_surukleme_yalnizca_siralayabilene(): void
    {
        [$user, $group] = $this->kurucu();
        $this->makine($group, 'srv-kutu', '10.0.9.9');

        $html = $this->actingAs($user)->get(route('os-updates.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-order="'.route('groups.reorder').'"', $html, 'yetkisiz sürükleyemez');
        $this->assertStringNotContainsString('data-order-id', $html);

        $html = $this->actingAs($this->yonetici())->get(route('os-updates.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-order="'.route('groups.reorder').'"', $html);
        $this->assertMatchesRegularExpression('/draggable="true" data-order-id="'.$group->uuid.'"/', $html);
    }

    /**
     * Makineye tıklayınca sağdaki kartta güncelleme geçmişi: kurulum ve kilit
     * girdileri. Görmediği kaydın adresi verilse de kart açılmaz.
     */
    #[Test]
    public function secili_makinenin_kartinda_guncelleme_gecmisi_gorunur(): void
    {
        [$user, $group] = $this->kurucu();
        $makine = $this->makine($group, 'srv-gecmis', '10.0.9.10');
        $kayit = app(ActivityRecorder::class);
        $kayit->record(ActivityAction::OsUpdate, 'Sistem güncellemesi kuruldu (kritik)', $makine, 'Aylık bakım', $user);
        $kayit->record(ActivityAction::OsUpdateLock, 'Güncelleme kilidi kondu', $makine, 'Üretim', $user);

        /* Başka makinenin girdisi bu kartta görünmez. */
        $baska = $this->makine($group, 'srv-baska', '10.0.9.11');
        $kayit->record(ActivityAction::OsUpdate, 'Başka makinenin kurulumu', $baska, 'X', $user);

        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $makine->uuid]))
            ->assertOk()
            ->assertSee('Güncelleme geçmişi')
            ->assertSeeInOrder(['Güncelleme kilidi kondu', 'Sistem güncellemesi kuruldu (kritik)', 'Aylık bakım'])
            ->assertDontSee('Başka makinenin kurulumu')
            ->assertSee('aria-selected="true"', false);

        /* Yetkisi olmadığı grubun makinesi: kart açılmaz, adı sızmaz. */
        $yabanciGrup = CredentialGroup::factory()->create();
        $yabanci = $this->makine($yabanciGrup, 'srv-yabanci', '10.0.9.12');

        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $yabanci->uuid]))
            ->assertOk()
            ->assertDontSee('srv-yabanci')
            ->assertDontSee('Güncelleme geçmişi');
    }

    private function yonetici(): User
    {
        $rol = Role::query()->where('is_admin', true)->first()
            ?? Role::create(['slug' => 'admin', 'label' => 'Yönetici', 'badge' => 'gray', 'is_admin' => true]);

        return User::factory()->create(['role_id' => $rol->getKey()]);
    }

    /** Teknik PC'ler: RDP (xrdp) ile kayıtlı Linux makineler geliyor, SSH 22'den; Windows RDP gelmiyor. */
    #[Test]
    public function rdp_ile_kayitli_linux_makine_gelir_ssh_portu_22_windows_rdp_gelmez(): void
    {
        [$user, $group] = $this->kurucu();
        $pc = $this->makine($group, 'Teknik-PC-Linux', '10.5.5.131', ['access_type' => AccessType::Rdp, 'port' => 3389]);
        $this->makine($group, 'Teknik-PC-Windows', '10.5.5.136', ['access_type' => AccessType::Rdp, 'operating_system' => OperatingSystem::Windows]);
        $ssh = $this->makine($group, 'Ssh-2222', '10.5.5.140', ['port' => 2222]);

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertOk()
            ->assertSee('Teknik-PC-Linux')
            ->assertDontSee('Teknik-PC-Windows');

        $this->assertSame(22, OsUpdateTargets::sshPort($pc));
        $this->assertSame('10.5.5.131:22', OsUpdateTargets::makine($pc));
        $this->assertSame(2222, OsUpdateTargets::sshPort($ssh));
    }

    /** Monitor kaydı işletim sistemi "bilinmiyor" diye SSH bilgisi olduğu hâlde gelmiyordu. */
    #[Test]
    public function isletim_sistemi_bilinmeyen_ssh_kaydi_gelir_gomulu_cihaz_ve_eksik_bilgili_gelmez(): void
    {
        [$user, $group] = $this->kurucu();
        $this->makine($group, 'Monitor-SSH', '10.0.0.7', ['operating_system' => OperatingSystem::Unknown]);
        $this->makine($group, 'Switch-SSH', '10.0.0.8', ['operating_system' => OperatingSystem::Embedded]);
        $this->makine($group, 'Sensor-SSH', '10.0.0.9', ['username' => null, 'password' => null]);

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertOk()
            ->assertSee('Monitor-SSH')
            ->assertDontSee('Switch-SSH')
            ->assertDontSee('Sensor-SSH');
    }

    /**
     * "Seçilenleri denetle" yalnızca seçilenleri denetler; seçim boş gelirse
     * (betik taşıyamadıysa) HEPSİ denetlenmez, istek reddedilir.
     */
    #[Test]
    public function secilenleri_denetle_yalnizca_secilenleri_bos_secim_hepsini_denetlemez(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $bir = $this->makine($group, 'Secili', '10.0.7.1');
        $this->makine($group, 'Secilmedi', '10.0.7.2');

        $this->actingAs($user)->post(route('os-updates.check'), ['secilen' => 1])
            ->assertSessionHasErrors('kayitlar');
        Queue::assertNothingPushed();

        $this->actingAs($user)->post(route('os-updates.check'), ['secilen' => 1, 'kayitlar' => [$bir->uuid]])
            ->assertSessionHas('status');
        Queue::assertPushed(CheckOsUpdatesJob::class, 1);
        Queue::assertPushed(CheckOsUpdatesJob::class, fn (CheckOsUpdatesJob $j): bool => $j->uniqueId() === 'denetim-elle:10.0.7.1:22');

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertSee('Seçilenleri denetle')
            ->assertSee('Hepsini şimdi denetle');
    }

    /** Denetim de kurulum gibi ilerleme kartı açar; sonuç geldikçe sayılır. */
    #[Test]
    public function denetim_ilerleme_karti_acar_ve_sonuclari_sayar(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $bir = $this->makine($group, 'Bir', '10.0.0.1');
        $iki = $this->makine($group, 'Iki', '10.0.0.2');
        $this->makine($group, 'Bir-root', '10.0.0.1');

        $this->actingAs($user)->post(route('os-updates.check'))->assertSessionHas('status');
        Queue::assertPushed(CheckOsUpdatesJob::class, 2);

        $parti = OsUpdateRound::son($user, OsUpdateRound::DENETIM);
        $this->assertNotNull($parti);
        $this->assertNull(OsUpdateRound::son($user), 'denetim kurulum kartının yerine geçmez');

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertOk()
            ->assertSee('Güncelleme denetimi')
            ->assertSee(route('os-updates.round.status', $parti), false);

        $this->actingAs($user)->getJson(route('os-updates.round.status', $parti))
            ->assertOk()
            ->assertJson(['running' => true, 'total' => 2, 'done' => 0]);

        $this->travel(1)->seconds();
        OsUpdateState::query()->create(['credential_id' => $bir->id, 'checked_at' => now(), 'check_error' => null]);
        OsUpdateState::query()->create(['credential_id' => $iki->id, 'checked_at' => now(), 'check_error' => 'Bağlanılamadı.']);

        $this->actingAs($user)->getJson(route('os-updates.round.status', $parti))
            ->assertOk()
            ->assertJson(['running' => false, 'done' => 2, 'success' => 1, 'failed' => 1])
            ->assertJsonPath('problems.0.status', 'Bağlanılamadı.');
    }

    /** Süren denetimin üstüne başlatılan denetim YENİ kart açmaz; kayıtlar aynı karta eklenir. */
    #[Test]
    public function suren_denetime_eklenen_denetim_ayni_karta_girer(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $bir = $this->makine($group, 'Bir', '10.0.0.1');
        $iki = $this->makine($group, 'Iki', '10.0.0.2');

        $this->actingAs($user)->post(route('os-updates.check'), ['kayitlar' => [$bir->uuid]])->assertSessionHas('status');
        $ilk = OsUpdateRound::son($user, OsUpdateRound::DENETIM);

        $this->travel(5)->seconds();
        $this->actingAs($user)->post(route('os-updates.check'), ['kayitlar' => [$iki->uuid]])->assertSessionHas('status');

        $this->assertSame($ilk, OsUpdateRound::son($user, OsUpdateRound::DENETIM), 'süren kart korunur');
        $this->actingAs($user)->getJson(route('os-updates.round.status', $ilk))
            ->assertJson(['running' => true, 'total' => 2, 'done' => 0]);

        /* İlk makine eklemeden ÖNCE, ikinci makine eklemeden SONRA bitti. */
        $this->travel(1)->seconds();
        OsUpdateState::query()->create(['credential_id' => $bir->id, 'checked_at' => now()->subSeconds(3)]);
        OsUpdateState::query()->create(['credential_id' => $iki->id, 'checked_at' => now()]);

        $this->actingAs($user)->getJson(route('os-updates.round.status', $ilk))
            ->assertJson(['running' => false, 'done' => 2, 'success' => 2]);
    }

    /** Biten turdan sonra başlatılan denetim yeni kart açar; tura sonradan eklenen makine eski sonucuyla bitmiş sayılmaz. */
    #[Test]
    public function bitmis_ture_eklenmez_yeniden_eklenen_makine_bastan_beklenir(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $bir = $this->makine($group, 'Bir', '10.0.0.1');
        $iki = $this->makine($group, 'Iki', '10.0.0.2');

        $ilk = OsUpdateRound::baslat($user, [$bir->id, $iki->id], OsUpdateRound::DENETIM);
        $this->travel(2)->seconds();
        OsUpdateState::query()->create(['credential_id' => $bir->id, 'checked_at' => now()]);

        /* Bir bitti, iki sürüyor: bir yeniden eklenince yeniden "sırada" olur. */
        $this->travel(2)->seconds();
        $this->assertSame($ilk, OsUpdateRound::baslat($user, [$bir->id], OsUpdateRound::DENETIM));
        $this->assertSame(['running' => true, 'done' => 0], array_intersect_key(OsUpdateRound::durum($user, $ilk), ['running' => 1, 'done' => 1]));

        $this->travel(1)->seconds();
        OsUpdateState::query()->whereKey(OsUpdateState::query()->where('credential_id', $bir->id)->value('id'))->update(['checked_at' => now()]);
        OsUpdateState::query()->create(['credential_id' => $iki->id, 'checked_at' => now()]);
        $this->assertFalse(OsUpdateRound::durum($user, $ilk)['running']);

        $this->assertNotSame($ilk, OsUpdateRound::baslat($user, [$bir->id], OsUpdateRound::DENETIM), 'biten tura eklenmez');
    }

    /** Kardeş kayıtta kurulum sürüyorsa ikinci kayıt sıraya alınmaz (iş makine başına tekil; sessizce düşüp "sırada" kalıyordu). */
    #[Test]
    public function kardes_kayitta_kurulum_surerken_ikinci_kayit_sirada_asili_kalmaz(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $dgn = $this->makine($group, 'dgn', '10.0.0.7');
        $root = $this->makine($group, 'root', '10.0.0.7', ['username' => 'root']);
        OsUpdateState::query()->create(['credential_id' => $dgn->id, 'apply_status' => OsUpdateState::SURUYOR, 'apply_queued_at' => now()]);

        $sonuc = app(OsUpdateLauncher::class)->kur(collect([$root]), OsUpdateScripts::KRITIK, $user, 'deneme');

        $this->assertSame([], $sonuc['queued']);
        $this->assertContains('kurulum zaten sürüyor', $sonuc['skipped']);
        $this->assertNull(OsUpdateState::query()->where('credential_id', $root->id)->value('apply_status'));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function ekran_yetkisiz_kullaniciya_kapali_yetkiliye_acik(): void
    {
        $yabanci = User::factory()->create();
        $this->actingAs($yabanci)->get(route('os-updates.index'))->assertForbidden();

        [$user, $group] = $this->kurucu();
        $this->makine($group, 'web-01', '10.0.0.5');

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertOk()
            ->assertSee('web-01')
            ->assertSee('Denetlenmedi');
    }

    /**
     * YETKİ PANELDEN: grubu yalnızca GÖREN kullanıcı (güncelleme yetkisi
     * verilmemiş) sekmeyi görmez, ekranı açamaz, hiçbir işlem yapamaz.
     * Panelde "Sistem güncellemesi kur" verilince sekme ve ekran açılır.
     */
    #[Test]
    public function guncelleme_yetkisi_panelden_verilmeyen_sekmeyi_gormez_islem_yapamaz(): void
    {
        Queue::fake();
        $group = CredentialGroup::factory()->create();
        $kayit = $this->makine($group, 'web-02', '10.0.0.6');

        $gorur = User::factory()->create();
        $this->yetkilendir($gorur, $group, ['view']);

        $this->actingAs($gorur)->get(route('credentials.index'))
            ->assertOk()
            ->assertDontSee(route('os-updates.index'));
        $this->actingAs($gorur)->get(route('os-updates.index'))->assertForbidden();

        $this->actingAs($gorur)->post(route('os-updates.check'))->assertForbidden();
        $this->actingAs($gorur)
            ->post(route('os-updates.apply'), ['kayitlar' => [$kayit->uuid], 'mod' => 'tumu', 'reason' => 'Deneme'])
            ->assertForbidden();
        $this->actingAs($gorur)
            ->post(route('os-updates.lock', $kayit), ['kilit' => 1, 'reason' => 'Deneme'])
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertNull($kayit->osUpdateState()->first());

        /* Panelde yetki verilen kullanıcıda sekme görünür, ekran açılır. */
        $kuran = User::factory()->create();
        $this->yetkilendir($kuran, $group, ['view', 'os_update']);

        $this->actingAs($kuran)->get(route('credentials.index'))->assertSee(route('os-updates.index'));
        $this->actingAs($kuran)->get(route('os-updates.index'))->assertOk()->assertSee('web-02');
    }

    /** @return array{0: User, 1: Role, 2: Credential, 3: Credential} */
    private function sunucuyaOzelSahne(): array
    {
        $group = CredentialGroup::factory()->create(['name' => 'Sunucular']);
        $rol = Role::create([
            'slug' => 'operator',
            'label' => 'Operatör',
            'badge' => 'gray',
            'is_admin' => false,
            /* Grupta yalnızca GÖRME var; güncelleme yetkisi grupta YOK. */
            'template' => ['credential_group' => ['all' => [], 'groups' => ['Sunucular' => ['view']]]],
        ]);
        $user = User::factory()->create(['role_id' => $rol->getKey()]);

        return [$user, $rol, $this->makine($group, 'web-a', '10.0.2.1'), $this->makine($group, 'web-b', '10.0.2.2')];
    }

    /**
     * SUNUCUYA ÖZEL YETKİ: rol grupta güncelleme kuramıyor ama tek bir
     * makinede kurabiliyor. Sekme açılır, yalnızca o makine kurulur;
     * gruptaki öteki makine ve kilit kapalı kalır.
     */
    #[Test]
    public function sunucuya_ozel_guncelleme_yetkisi_yalnizca_o_makineye_kurar(): void
    {
        Queue::fake();
        [$user, $rol, $izinli, $oteki] = $this->sunucuyaOzelSahne();

        /* İzin yokken sekme ve ekran kapalı. */
        $this->actingAs($user)->get(route('credentials.index'))->assertDontSee(route('os-updates.index'));
        $this->actingAs($user)->get(route('os-updates.index'))->assertForbidden();

        $izinli->grantedRoles()->attach($rol, ['action' => 'os_update']);
        $user = $user->fresh();

        $this->assertTrue($user->can('osUpdate', $izinli));
        $this->assertFalse($user->can('osUpdate', $oteki));
        $this->assertFalse($user->can('osUpdateLock', $izinli), 'Sunucuya özel izin kilit yetkisi vermez.');

        $this->actingAs($user)->get(route('credentials.index'))->assertSee(route('os-updates.index'));
        $html = $this->actingAs($user)->get(route('os-updates.index', ['grup' => 'hepsi']))
            ->assertOk()->assertSee('web-a')->assertSee('web-b')->getContent();
        $this->assertSame(1, substr_count($html, 'data-bulk-item'), 'Yalnızca izinli makine seçilebilir.');

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$izinli->uuid, $oteki->uuid], 'mod' => 'tumu', 'reason' => 'Yama'])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '1 makineye') && str_contains($m, 'web-b (yetki yok)'));

        Queue::assertPushed(ApplyOsUpdatesJob::class, 1);
        Queue::assertPushed(ApplyOsUpdatesJob::class, fn (ApplyOsUpdatesJob $j): bool => $j->uniqueId() === 'kurulum:10.0.2.1:22');

        $this->actingAs($user)
            ->post(route('os-updates.lock', $izinli), ['kilit' => 1, 'reason' => 'Deneme'])
            ->assertForbidden();
    }

    /** Kayıt kısıtı izni yener: rol bu kayda kapalıysa sunucuya özel izin de geçmez, sekme açılmaz. */
    #[Test]
    public function kayit_kisiti_sunucuya_ozel_guncelleme_iznini_yener(): void
    {
        [$user, $rol, $izinli] = $this->sunucuyaOzelSahne();
        $izinli->grantedRoles()->attach($rol, ['action' => 'os_update']);
        $izinli->restrictedRoles()->attach($rol);
        $user = $user->fresh();

        $this->assertFalse($user->can('osUpdate', $izinli));
        $this->assertFalse($user->can('osUpdatePage', Credential::class));
    }

    /** Sunucuya özel izni kayıt formundan YALNIZCA yönetici yazar; boş gönderim kaldırır. */
    #[Test]
    public function sunucuya_ozel_guncelleme_izni_formdan_yalnizca_yonetici_yazar(): void
    {
        [$user, $rol, $izinli] = $this->sunucuyaOzelSahne();
        $alanlar = [
            'group' => $izinli->group->uuid,
            'name' => $izinli->name,
            'access_type' => $izinli->access_type->value,
            'operating_system' => $izinli->operating_system->value,
            'host' => $izinli->host,
            'rol_kutulari' => '1',
        ];

        /* Yönetici olmayan (düzenleme yetkili) gönderirse alan okunmaz. */
        $duzenleyen = User::factory()->create();
        $this->yetkilendir($duzenleyen, $izinli->group, ['view', 'update']);
        $this->actingAs($duzenleyen)
            ->put(route('credentials.update', $izinli), [...$alanlar, 'guncelleme_roles' => [$rol->uuid]]);
        $this->assertFalse($user->fresh()->can('osUpdate', $izinli->fresh()));

        $yonetici = $this->yonetici();
        $this->actingAs($yonetici)
            ->put(route('credentials.update', $izinli), [...$alanlar, 'guncelleme_roles' => [$rol->uuid]])
            ->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->can('osUpdate', $izinli->fresh()));

        /* Düzenleme formu kutuyu işaretli getirir. */
        $this->actingAs($yonetici)->get(route('credentials.index', ['kayit' => $izinli->uuid]))
            ->assertOk()
            ->assertSee('name="guncelleme_roles[]" value="'.$rol->uuid.'"', false);

        /* Tik kaldırılınca (alan gelmez, yalnızca formun işareti) izin kalkar. */
        $this->actingAs($yonetici)->put(route('credentials.update', $izinli), $alanlar)->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->can('osUpdate', $izinli->fresh()));
    }

    /** Yalnızca kilit yetkisi: ekran açılır ama toplu araçlar ve seçim kutuları çizilmez. */
    #[Test]
    public function yalnizca_kilit_yetkisinde_toplu_araclar_cizilmez(): void
    {
        $group = CredentialGroup::factory()->create();
        $kayit = $this->makine($group, 'web-03', '10.0.0.7');
        $kilitci = User::factory()->create();
        $this->yetkilendir($kilitci, $group, ['view', 'os_update_lock']);

        $html = $this->actingAs($kilitci)->get(route('os-updates.index', ['kayit' => $kayit->uuid]))
            ->assertOk()
            ->assertSee('web-03')
            ->getContent();

        $this->assertStringNotContainsString('Toplu işlemler', $html);
        $this->assertStringNotContainsString('data-bulk-item', $html);
        $this->assertStringNotContainsString(route('os-updates.check'), $html);

        /* Kilit düğmesi kartta durur. */
        $this->assertStringContainsString(route('os-updates.lock', $kayit), $html);
    }

    #[Test]
    public function ayni_adli_kayitlar_alt_grup_adiyla_ayrilir(): void
    {
        [$user, $group] = $this->kurucu();
        $noc = SubGroup::factory()->for($group, 'group')->create(['name' => 'DGN_NOC']);
        $db = SubGroup::factory()->for($group, 'group')->create(['name' => 'KAPI_DB']);
        $this->makine($group, 'SSH', '10.0.0.1', ['alt_grup_id' => $noc->id]);
        $this->makine($group, 'SSH', '10.0.0.2', ['alt_grup_id' => $db->id]);

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertOk()
            ->assertSee('DGN_NOC · SSH')
            ->assertSee('KAPI_DB · SSH');
    }

    #[Test]
    public function secilen_makineler_siraya_alinir_gerekce_zorunlu(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $bir = $this->makine($group, 'Bir', '10.0.0.1');
        $iki = $this->makine($group, 'Iki', '10.0.0.2');

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$bir->uuid, $iki->uuid], 'mod' => 'kritik'])
            ->assertSessionHasErrors('reason');

        Queue::assertNothingPushed();

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$bir->uuid, $iki->uuid], 'mod' => 'kritik', 'reason' => 'Aylık yama'])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '2 makineye'));

        Queue::assertPushed(ApplyOsUpdatesJob::class, 2);
        $this->assertSame(OsUpdateState::SIRADA, $bir->osUpdateState()->first()->apply_status);
    }

    #[Test]
    public function kilitli_makine_toplu_secimde_atlanir(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $kritik = $this->makine($group, 'DB-Ana', '10.0.0.9');
        $normal = $this->makine($group, 'Web', '10.0.0.10');
        OsUpdateState::create(['credential_id' => $kritik->id, 'locked' => true, 'lock_reason' => 'Üretim veritabanı']);

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$kritik->uuid, $normal->uuid], 'mod' => 'tumu', 'reason' => 'Toplu yama'])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '1 makineye') && str_contains($m, 'DB-Ana (kilitli)'));

        Queue::assertPushed(ApplyOsUpdatesJob::class, 1);
        Queue::assertPushed(ApplyOsUpdatesJob::class, fn (ApplyOsUpdatesJob $j): bool => $j->uniqueId() === 'kurulum:10.0.0.10:22');
    }

    /** Kilit MAKİNEYİ korur: aynı adresin kilitsiz kaydından kurulum başlatılamaz. */
    #[Test]
    public function kardes_kayittaki_kilit_de_kurulumu_engeller(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $root = $this->makine($group, 'DB root', '10.0.0.9', ['username' => 'root']);
        $dgn = $this->makine($group, 'DB dgn', '10.0.0.9');
        OsUpdateState::create(['credential_id' => $root->id, 'locked' => true, 'lock_reason' => 'Kritik']);

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$dgn->uuid], 'mod' => 'tumu', 'reason' => 'Deneme'])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    /** Kuyrukta beklerken konan kilit: iş makineye bağlanmadan durur. */
    #[Test]
    public function kuyrukta_beklerken_kilitlenen_makineye_dokunulmaz(): void
    {
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'App', '10.0.0.20');
        OsUpdateState::create(['credential_id' => $kayit->id, 'locked' => true, 'lock_reason' => 'Sonradan kilitlendi']);

        (new ApplyOsUpdatesJob($kayit, OsUpdateScripts::TUMU, $user, 'yama'))->handle(app(OsUpdater::class), app(OsUpdateTargets::class));

        $this->assertSame([], $this->calisan, 'kilitli makinede komut çalışmamalı');
        $this->assertSame(OsUpdateState::ATLANDI, $kayit->osUpdateState()->first()->apply_status);
    }

    #[Test]
    public function secim_yetki_genisletmez_baska_grubun_makinesi_atlanir(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $digerGrup = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $digerGrup, ['view']);
        $yabanci = $this->makine($digerGrup, 'Baska', '10.0.1.1');

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$yabanci->uuid], 'mod' => 'tumu', 'reason' => 'Deneme'])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function kurma_yetkisi_kilidi_kaldiramaz_kilit_yetkisi_kurulum_yapamaz(): void
    {
        Queue::fake();
        [$kurucu, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'DB', '10.0.0.30');
        OsUpdateState::create(['credential_id' => $kayit->id, 'locked' => true, 'lock_reason' => 'Kritik']);

        $this->actingAs($kurucu)
            ->post(route('os-updates.lock', $kayit), ['kilit' => 0, 'reason' => 'Kaldırayım'])
            ->assertForbidden();

        $this->assertTrue($kayit->osUpdateState()->first()->locked);

        $kilitci = User::factory()->create();
        $this->yetkilendir($kilitci, $group, ['view', 'os_update_lock']);

        $this->actingAs($kilitci)
            ->post(route('os-updates.apply'), ['kayitlar' => [$kayit->uuid], 'mod' => 'tumu', 'reason' => 'Deneme'])
            ->assertForbidden();

        $this->actingAs($kilitci)->get(route('os-updates.index'))->assertOk();
    }

    #[Test]
    public function kilit_gerekceyle_konur_ve_islem_kaydina_yazilir(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'os_update_lock']);
        $kayit = $this->makine($group, 'DB', '10.0.0.40');

        $this->actingAs($user)->post(route('os-updates.lock', $kayit), ['kilit' => 1])->assertSessionHasErrors('reason');

        $this->actingAs($user)
            ->post(route('os-updates.lock', $kayit), ['kilit' => 1, 'reason' => 'Üretim sunucusu'])
            ->assertSessionHas('status');

        $durum = $kayit->osUpdateState()->first();
        $this->assertTrue($durum->locked);
        $this->assertSame($user->id, $durum->locked_by);

        $log = ActivityLog::query()->where('action', ActivityAction::OsUpdateLock->value)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Üretim sunucusu', $log->reason);
    }

    #[Test]
    public function windows_kisisel_ve_pasif_kayit_hedef_degildir(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $win = $this->makine($group, 'WinRDP-kaydi', '10.0.2.1', ['operating_system' => OperatingSystem::Windows, 'access_type' => AccessType::Rdp]);
        $pasif = $this->makine($group, 'Pasif', '10.0.2.2', ['is_active' => false]);
        $kisisel = $this->makine($group, 'Kisisel', '10.0.2.3', ['owner_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$win->uuid, $pasif->uuid, $kisisel->uuid], 'mod' => 'tumu', 'reason' => 'Deneme'])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertDontSee('WinRDP-kaydi')->assertDontSee('Pasif')->assertDontSee('Kisisel');
    }

    #[Test]
    public function denetim_sonucu_makinenin_butun_kayitlarina_yazilir(): void
    {
        [$user, $group] = $this->kurucu();
        $root = $this->makine($group, 'Srv root', '10.0.3.1', ['username' => 'root']);
        $dgn = $this->makine($group, 'Srv dgn', '10.0.3.1');

        (new CheckOsUpdatesJob($root))->handle(app(OsUpdater::class), app(OsUpdateTargets::class));

        foreach ([$root, $dgn] as $kayit) {
            $durum = $kayit->osUpdateState()->first();
            $this->assertSame(1, $durum->critical_count);
            $this->assertSame(1, $durum->normal_count);
            $this->assertTrue($durum->reboot_required);
            $this->assertNotNull($durum->checked_at);
        }

        $this->assertCount(1, $this->calisan);
        $this->assertStringNotContainsString('setsid', $this->calisan[0]);
    }

    #[Test]
    public function kurulum_basarili_olunca_kaydedilir_ve_yeniden_denetlenir(): void
    {
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'App', '10.0.4.1');

        app(OsUpdater::class)->kur($kayit, OsUpdateScripts::KRITIK, $user, 'Aylık yama');

        $this->assertCount(2, $this->calisan);
        $this->assertStringContainsString('setsid', $this->calisan[0]);
        $this->assertSame(OsUpdateState::BASARILI, $kayit->osUpdateState()->first()->apply_status);

        /* Yeniden denetim işletim sistemi adını da yazar; ekranda adın altında durur. */
        $this->assertSame('Ubuntu 24.04.3 LTS', $kayit->osUpdateState()->first()->os_name);
        $this->actingAs($user)->get(route('os-updates.index'))->assertSee('Ubuntu 24.04.3 LTS');

        $log = ActivityLog::query()->where('action', ActivityAction::OsUpdate->value)->latest('id')->first();
        $this->assertSame('Aylık yama', $log->reason);
        $this->assertStringContainsString('yalnızca kritik', $log->summary);
    }

    /** Başarısız kurulumun nedeni ekranda: yalnızca "kod 1" değil, günlüğün son satırları. Parola maskelenir. */
    #[Test]
    public function basarisiz_kurulum_gunlugun_son_satirlarini_gosterir(): void
    {
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'Rocky', '10.0.4.2');
        $this->kurulumCiktisi = "EYS-SONUC:1\nEYS-GUNLUK:bas\nError: Problem: cannot install the best update candidate\nEYS-GUNLUK:son\n";

        app(OsUpdater::class)->kur($kayit, OsUpdateScripts::KRITIK, $user, 'Aylık yama');

        $durum = $kayit->osUpdateState()->first();
        $this->assertSame(OsUpdateState::BASARISIZ, $durum->apply_status);
        $this->assertSame('Kurulum hatayla bitti (kod 1). Error: Problem: cannot install the best update candidate', $durum->apply_error);
        $this->assertCount(2, $this->calisan, 'hatayla biten kurulum kısmen kurmuş olabilir: hemen yeniden denetlenir');
        $this->assertNotNull($durum->checked_at);
        $this->assertSame(1, $durum->critical_count, 'bekleyen sayılar denetimden tazelenir');
    }

    /**
     * Süresi dolan kurulumun sonucu okunamaz; kurulum makinede sürüyor olabilir.
     * Bekleyen sayılar eskide kalmasın diye gecikmeli bir denetim sıraya girer
     * (hemen denetim paket yöneticisinin kilidine takılırdı).
     */
    #[Test]
    public function sonucu_okunamayan_kurulum_gecikmeli_yeniden_denetlenir(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'Mint', '10.0.4.3');
        $this->kurulumCiktisi = "Reading package lists...\n";

        app(OsUpdater::class)->kur($kayit, OsUpdateScripts::KRITIK, $user, 'Aylık yama');

        $durum = $kayit->osUpdateState()->first();
        $this->assertSame(OsUpdateState::BASARISIZ, $durum->apply_status);
        $this->assertStringContainsString('sonucu okunamadı', $durum->apply_error);
        $this->assertStringContainsString('15 dk sonra yeniden denetlenir', $durum->apply_error);
        $this->assertCount(1, $this->calisan, 'kurulum sürüyor olabilir: hemen bağlanılmaz');
        Queue::assertPushed(CheckOsUpdatesJob::class, fn (CheckOsUpdatesJob $is): bool => $is->uniqueId() === (new CheckOsUpdatesJob($kayit, elle: true))->uniqueId()
            && $is->delay !== null);
    }

    /** Makineye ulaşılamayan kurulumdan sonra denetim de denenmez. */
    #[Test]
    public function baglanamayan_kurulumdan_sonra_yeniden_denetlenmez(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'Kapali', '10.0.4.4');
        $this->kurulumBaglantiHatasi = 'Kimlik doğrulaması reddedildi.';

        app(OsUpdater::class)->kur($kayit, OsUpdateScripts::KRITIK, $user, 'Aylık yama');

        $this->assertSame(OsUpdateState::BASARISIZ, $kayit->osUpdateState()->first()->apply_status);
        $this->assertCount(1, $this->calisan);
        Queue::assertNotPushed(CheckOsUpdatesJob::class);
    }

    /** Elle denetim, otomatik turun beklettiği işin arkasında kalmaz; o arada denetlenen makineye otomatik iş yeniden bağlanmaz. */
    #[Test]
    public function elle_denetim_otomatik_sirayi_beklemez_otomatik_is_tekrar_baglanmaz(): void
    {
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'Srv', '10.0.6.1');

        $otomatik = new CheckOsUpdatesJob($kayit);
        $elle = new CheckOsUpdatesJob($kayit, elle: true);
        $this->assertNotSame($otomatik->uniqueId(), $elle->uniqueId(), 'elle iş otomatik işin tekillik kilidine takılmaz');

        $this->travel(1)->seconds();
        $elle->handle(app(OsUpdater::class), app(OsUpdateTargets::class));
        $this->assertCount(1, $this->calisan);

        $otomatik->handle(app(OsUpdater::class), app(OsUpdateTargets::class));
        $this->assertCount(1, $this->calisan, 'beklerken denetlenen makineye yeniden bağlanılmaz');

        $this->travel(1)->seconds();
        (new CheckOsUpdatesJob($kayit))->handle(app(OsUpdater::class), app(OsUpdateTargets::class));
        $this->assertCount(2, $this->calisan, 'sonradan sıraya alınan otomatik iş bağlanır');
    }

    /** Yeniden başlatma AYRI yetki: kurulum yetkisi kapsamaz; düğme yetkisize çizilmez. */
    #[Test]
    public function yeniden_baslatma_kurulum_yetkisiyle_yapilamaz(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'Srv', '10.0.8.1');
        OsUpdateState::query()->create(['credential_id' => $kayit->id, 'reboot_required' => true, 'checked_at' => now()]);

        $this->actingAs($user)->post(route('os-updates.reboot', $kayit), ['reason' => 'Çekirdek yaması'])->assertForbidden();
        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $kayit->uuid]))
            ->assertOk()
            ->assertDontSee(route('os-updates.reboot', $kayit), false);
        Queue::assertNothingPushed();
    }

    /** Yalnızca "yeniden başlatma gerekli" olan, kilitsiz makine; gerekçe zorunlu; üst üste ikinci kez gönderilmez. */
    #[Test]
    public function yeniden_baslatma_dar_kapidan_ve_gerekceyle_siraya_girer(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu(['os_reboot']);
        $kayit = $this->makine($group, 'Srv', '10.0.8.2');
        $durum = OsUpdateState::query()->create(['credential_id' => $kayit->id, 'reboot_required' => false, 'checked_at' => now()]);

        $this->actingAs($user)->post(route('os-updates.reboot', $kayit), ['reason' => 'Çekirdek yaması'])->assertSessionHas('error');
        Queue::assertNothingPushed();

        $durum->update(['reboot_required' => true]);
        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $kayit->uuid]))
            ->assertSee(route('os-updates.reboot', $kayit), false);

        $this->actingAs($user)->post(route('os-updates.reboot', $kayit), [])->assertSessionHasErrors('reason');
        Queue::assertNothingPushed();

        $this->actingAs($user)->post(route('os-updates.reboot', $kayit), ['reason' => 'Çekirdek yaması'])->assertSessionHas('status');
        Queue::assertPushed(RebootOsJob::class, 1);

        $this->actingAs($user)->post(route('os-updates.reboot', $kayit), ['reason' => 'Çekirdek yaması'])->assertSessionHas('error');
        Queue::assertPushed(RebootOsJob::class, 1);

        /* Kilitli makine: düğme yok, uç reddeder. */
        $diger = $this->makine($group, 'Kritik', '10.0.8.3');
        OsUpdateState::query()->create(['credential_id' => $diger->id, 'reboot_required' => true, 'locked' => true, 'lock_reason' => 'Üretim DB']);
        $this->actingAs($user)->post(route('os-updates.reboot', $diger), ['reason' => 'Çekirdek yaması'])->assertSessionHas('error');
        Queue::assertPushed(RebootOsJob::class, 1);
    }

    /** İş: komut makinede çalışır, gerekçe işlem kaydına yazılır, makine kalkınca yeniden denetlenir. */
    #[Test]
    public function yeniden_baslatma_isi_kaydeder_ve_sonra_denetler(): void
    {
        [$user, $group] = $this->kurucu(['os_reboot']);
        $kayit = $this->makine($group, 'Srv', '10.0.8.4');
        OsUpdateState::query()->create(['credential_id' => $kayit->id, 'reboot_required' => true, 'checked_at' => now()]);

        Queue::fake([CheckOsUpdatesJob::class]);
        (new RebootOsJob($kayit, $user, 'Çekirdek yaması'))->handle(app(OsRebooter::class));

        $this->assertSame([OsUpdateScripts::yenidenBaslatma()], $this->calisan);
        $log = ActivityLog::query()->where('action', ActivityAction::OsReboot->value)->latest('id')->first();
        $this->assertSame('Çekirdek yaması', $log->reason);
        Queue::assertPushed(CheckOsUpdatesJob::class, 1);
    }

    /**
     * 17.11.2026, test sunucuları kapalı: komut gidemedi, satır "Yeniden
     * başlatma gerekli" ile sessizce kaldı. Artık denetim hemen sıraya girer;
     * bağlantı hatası satırda görünür, aynı makine yeniden denenebilir.
     */
    #[Test]
    public function yeniden_baslatilamazsa_hemen_denetlenir(): void
    {
        [$user, $group] = $this->kurucu(['os_reboot']);
        $kayit = $this->makine($group, 'Srv', '10.0.8.5');
        OsUpdateState::query()->create(['credential_id' => $kayit->id, 'reboot_required' => true, 'checked_at' => now()]);
        $this->yenidenBaslatmaHatasi = 'Bağlantı kurulamadı: No route to host';

        Queue::fake([CheckOsUpdatesJob::class, RebootOsJob::class]);
        $this->assertNull(app(OsRebooter::class)->baslat($kayit, $user, 'Çekirdek'));
        (new RebootOsJob($kayit, $user, 'Çekirdek'))->handle(app(OsRebooter::class));

        Queue::assertPushed(CheckOsUpdatesJob::class, fn (CheckOsUpdatesJob $is): bool => $is->delay === null);
        $this->assertStringStartsWith('Yeniden başlatılamadı:', ActivityLog::query()->latest('id')->value('summary'));
        $this->assertNull(app(OsRebooter::class)->baslat($kayit, $user, 'Tekrar'), 'başarısızlık sonrası yeniden denenebilir');
    }

    #[Test]
    public function zamanlayici_kapaliyken_hicbir_makineye_baglanmaz(): void
    {
        Queue::fake();
        config(['os_update.check_hours' => 0]);
        $group = CredentialGroup::factory()->create();
        $this->makine($group, 'A', '10.0.5.1');

        $this->artisan('os-update:check')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function zamanlayici_yalnizca_zamani_gelen_makineyi_bir_kez_denetler(): void
    {
        Queue::fake();
        config(['os_update.check_hours' => 4]);
        $group = CredentialGroup::factory()->create();

        $yeni = $this->makine($group, 'Yeni', '10.0.6.1');
        $this->makine($group, 'Yeni ikinci hesap', '10.0.6.1');
        $taze = $this->makine($group, 'Taze', '10.0.6.2');
        $eski = $this->makine($group, 'Eski', '10.0.6.3');
        OsUpdateState::create(['credential_id' => $taze->id, 'checked_at' => now()->subHour()]);
        OsUpdateState::create(['credential_id' => $eski->id, 'checked_at' => now()->subHours(5)]);

        $this->artisan('os-update:check')->assertSuccessful();

        Queue::assertPushed(CheckOsUpdatesJob::class, 2);
        Queue::assertPushed(CheckOsUpdatesJob::class, fn (CheckOsUpdatesJob $j): bool => $j->uniqueId() === 'denetim:10.0.6.1:22');
        Queue::assertPushed(CheckOsUpdatesJob::class, fn (CheckOsUpdatesJob $j): bool => $j->uniqueId() === 'denetim:10.0.6.3:22');
        Queue::assertNotPushed(ApplyOsUpdatesJob::class);
        $this->assertNotNull($yeni);
    }

    /**
     * EYS'nin kendi sunucusu (16.11.2026: Docker yükseltmesi EYS'yi kilitledi,
     * bütün siteler düştü): kilit olmasa da kurulum ve yeniden başlatma yok,
     * ekranda seçilemez; denetim serbest.
     */
    #[Test]
    public function panelin_kendi_sunucusuna_kurulum_ve_yeniden_baslatma_yapilmaz(): void
    {
        Queue::fake();
        config(['os_update.panel_hosts' => '10.0.9.9, 10.0.9.10']);
        [$user, $group] = $this->kurucu(['os_reboot']);
        $panel = $this->makine($group, 'NineDeploy', '10.0.9.9');
        $diger = $this->makine($group, 'App', '10.0.9.11');
        OsUpdateState::query()->create(['credential_id' => $panel->id, 'reboot_required' => true, 'checked_at' => now()]);

        $sonuc = app(OsUpdateLauncher::class)->kur(collect([$panel, $diger]), OsUpdateScripts::TUMU, $user, 'deneme');
        $this->assertSame([$diger->id], $sonuc['queued']);
        $this->assertSame(OsUpdater::PANEL_SUNUCUSU, $sonuc['skipped']['NineDeploy'] ?? null);

        /* Sıraya önceden girmiş iş de bağlanmadan biter. */
        app(OsUpdater::class)->kur($panel, OsUpdateScripts::TUMU, $user, 'deneme');
        $this->assertSame([], $this->calisan);
        $this->assertSame(OsUpdateState::ATLANDI, $panel->osUpdateState()->first()->apply_status);

        $this->actingAs($user)->post(route('os-updates.reboot', $panel), ['reason' => 'Çekirdek yaması'])->assertSessionHas('error');
        Queue::assertNotPushed(RebootOsJob::class);

        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $panel->uuid]))
            ->assertSee('NineDeploy seçilemez: '.OsUpdater::PANEL_SUNUCUSU, false)
            ->assertDontSee(route('os-updates.reboot', $panel), false);

        $this->assertSame([$panel->id], app(OsUpdateLauncher::class)->denetle(collect([$panel]), $user), 'denetim serbest');
    }

    /**
     * Kuyruk silinince (Redis yeniden başladı) "sırada/kuruluyor" kayıt asılı
     * kalıp makineyi seçilemez yapıyordu. Kuyruk boşsa düşer; kuyrukta iş
     * varsa ya da kayıt yeni değiştiyse dokunulmaz.
     */
    #[Test]
    public function kuyrukta_isi_olmayan_asili_kurulum_dusurulur(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu();
        $asili = $this->makine($group, 'Asili', '10.0.9.20');
        $yeni = $this->makine($group, 'Yeni', '10.0.9.21');
        OsUpdateState::query()->create(['credential_id' => $asili->id, 'apply_status' => OsUpdateState::SIRADA, 'apply_queued_at' => now()]);
        $this->travel(10)->minutes();
        OsUpdateState::query()->create(['credential_id' => $yeni->id, 'apply_status' => OsUpdateState::SURUYOR, 'apply_queued_at' => now()]);

        /* Kuyrukta bir iş varsa hiçbir kayda dokunulmaz. */
        CheckOsUpdatesJob::dispatch($yeni, elle: true);
        $this->artisan('os-update:reconcile')->assertSuccessful();
        $this->assertSame(OsUpdateState::SIRADA, $asili->osUpdateState()->first()->apply_status);

        Queue::fake();
        $this->artisan('os-update:reconcile')->assertSuccessful();

        $this->assertSame(OsUpdateState::BASARISIZ, $asili->osUpdateState()->first()->apply_status);
        $this->assertStringContainsString('kuyrukta yok', $asili->osUpdateState()->first()->apply_error);
        $this->assertSame(OsUpdateState::SURUYOR, $yeni->osUpdateState()->first()->apply_status, 'yeni değişen kayda dokunulmaz');
    }

    /** /boot'ta yer azsa ekran kurulumdan ÖNCE uyarır; yeterliyse susar. */
    #[Test]
    public function boot_dolu_makine_ekranda_uyarilir(): void
    {
        [$user, $group] = $this->kurucu();
        $dolu = $this->makine($group, 'Barkod', '10.0.9.30');
        $rahat = $this->makine($group, 'Rahat', '10.0.9.31');
        OsUpdateState::query()->create(['credential_id' => $dolu->id, 'package_manager' => 'dnf', 'checked_at' => now(), 'boot_free_mb' => 40]);
        OsUpdateState::query()->create(['credential_id' => $rahat->id, 'package_manager' => 'apt', 'checked_at' => now(), 'boot_free_mb' => 700]);

        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $dolu->uuid]))
            ->assertOk()
            ->assertSeeText('/boot dolu')
            ->assertSeeText('/boot bölümünde yalnızca 40 MB boş');

        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $rahat->uuid]))
            ->assertOk()
            ->assertDontSeeText('/boot bölümünde yalnızca');
    }

    /**
     * KAYIT bazlı hariç (17.11.2026, Barkod Yazıcı): aynı makinede root ve
     * root yetkisi olmayan dgnbarkod kaydı. Hariç kayıtla hiçbir yoldan
     * bağlanılmaz; makine kilitlenmez, root kaydından kurulur ve denetlenir.
     */
    #[Test]
    public function haric_kayitla_baglanilmaz_makine_kardesten_guncellenir(): void
    {
        Queue::fake();
        [$user, $group] = $this->kurucu(['os_update_lock', 'os_reboot']);
        $root = $this->makine($group, 'Barkod', '10.0.9.40', ['username' => 'root']);
        $dgn = $this->makine($group, 'Barkod (dgnbarkod)', '10.0.9.40', ['username' => 'dgnbarkod']);
        OsUpdateState::query()->create(['credential_id' => $dgn->id, 'checked_at' => now(), 'reboot_required' => true,
            'apply_status' => OsUpdateState::BASARISIZ, 'apply_error' => 'Kimlik doğrulaması reddedildi (dgnbarkod).']);

        $this->actingAs($user)->post(route('os-updates.exclude', $dgn), ['haric' => 1])->assertSessionHasErrors('reason');
        $this->actingAs($user)->post(route('os-updates.exclude', $dgn), ['haric' => 1, 'reason' => 'root yetkisi yok'])
            ->assertSessionHas('status');

        $durum = $dgn->osUpdateState()->first();
        $this->assertTrue($durum->excluded);
        $this->assertNull($durum->apply_status, 'eski "Kurulamadı" silinir');
        $this->assertNull(app(OsUpdateTargets::class)->kilit($root), 'makine kilitlenmez');

        $sonuc = app(OsUpdateLauncher::class)->kur(collect([$root, $dgn]), OsUpdateScripts::TUMU, $user, 'deneme');
        $this->assertSame([$root->id], $sonuc['queued']);
        $this->assertSame('güncellemeden hariç', $sonuc['skipped']['Barkod (dgnbarkod)'] ?? null);

        app(OsUpdater::class)->kur($dgn, OsUpdateScripts::TUMU, $user, 'deneme');
        $this->assertSame([], $this->baglanan, 'sıradaki iş de hariç kayıtla bağlanmaz');

        $this->actingAs($user)->post(route('os-updates.reboot', $dgn), ['reason' => 'Çekirdek'])->assertSessionHas('error');
        Queue::assertNotPushed(RebootOsJob::class);

        $this->assertSame([], app(OsUpdateLauncher::class)->denetle(collect([$dgn]), $user));

        /* Sistemin kendi denetimi (gecikmeli/zamanlanmış) kardeşle bağlanır, sonuç ikisine de yazılır. */
        app(OsUpdater::class)->denetle($dgn);
        $this->assertSame([$root->id], $this->baglanan);
        $this->assertSame(1, $dgn->osUpdateState()->first()->critical_count);

        $this->actingAs($user)->get(route('os-updates.index', ['kayit' => $dgn->uuid]))
            ->assertOk()
            ->assertSeeText('Hariç')
            ->assertSee('Barkod (dgnbarkod) seçilemez: Güncellemeden hariç: root yetkisi yok', false)
            ->assertDontSee('Barkod seçilemez: Güncellemeden hariç', false)
            ->assertSee(route('os-updates.exclude', $dgn), false);

        $this->actingAs($user)->post(route('os-updates.exclude', $dgn), ['haric' => 0, 'reason' => 'Parola düzeldi'])
            ->assertSessionHas('status');
        $this->assertFalse($dgn->osUpdateState()->first()->excluded);
    }

    /** Hariç tutmak kilit yetkisi ister; kurma yetkisi yetmez. */
    #[Test]
    public function haric_tutmak_kilit_yetkisi_ister(): void
    {
        [$user, $group] = $this->kurucu();
        $kayit = $this->makine($group, 'Srv', '10.0.9.41');

        $this->actingAs($user)->post(route('os-updates.exclude', $kayit), ['haric' => 1, 'reason' => 'Deneme'])->assertForbidden();
        $this->assertFalse(OsUpdateState::query()->where('credential_id', $kayit->id)->where('excluded', true)->exists());
    }

    /** Zamanlanmış denetim makine başına hariç OLMAYAN kaydı seçer (eskisi önce gelse de). */
    #[Test]
    public function zamanlanmis_denetim_haric_kaydi_secmez(): void
    {
        Queue::fake();
        [, $group] = $this->kurucu();
        $dgn = $this->makine($group, 'Barkod (dgnbarkod)', '10.0.9.42', ['username' => 'dgnbarkod']);
        $root = $this->makine($group, 'Barkod', '10.0.9.42', ['username' => 'root']);
        OsUpdateState::query()->create(['credential_id' => $dgn->id, 'excluded' => true, 'exclude_reason' => 'root yetkisi yok']);

        $this->artisan('os-update:check', ['--hepsi' => true])->assertSuccessful();

        Queue::assertPushed(CheckOsUpdatesJob::class, 1);
        Queue::assertPushed(CheckOsUpdatesJob::class, fn (CheckOsUpdatesJob $is): bool => (fn () => $this->credential)->call($is)->is($root));
    }

    /** Alt grup başlığındaki kutu yalnızca o grubun seçilebilen satırlarını seçer; seçilebilen yoksa kapalı. */
    #[Test]
    public function alt_grup_basliginda_hepsini_sec_kutusu_var(): void
    {
        $user = User::factory()->create();
        $sunucu = CredentialGroup::factory()->create(['name' => 'Sunucular', 'position' => 1]);
        $teknik = CredentialGroup::factory()->create(['name' => 'Teknik PCler', 'position' => 2]);
        $this->yetkilendir($user, $sunucu, ['view', 'os_update']);
        $this->yetkilendir($user, $teknik, ['view', 'os_update']);
        $kilitli = $this->makine($sunucu, 'DB', '10.0.9.50');
        OsUpdateState::query()->create(['credential_id' => $kilitli->id, 'locked' => true, 'lock_reason' => 'Üretim']);
        $pc = $this->makine($teknik, 'PC-1', '10.0.9.51');

        $html = $this->actingAs($user)->get(route('os-updates.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-bulk-group-all="0"\s+disabled/', $html, 'kilitli tek makinenin grubu: kapalı');
        $this->assertMatchesRegularExpression('/data-bulk-group-all="1"\s+title="1\/1 seçilebilir"/', $html);
        $this->assertMatchesRegularExpression('/value="'.$pc->uuid.'" data-bulk-item\s+data-bulk-group="1"/', $html);
    }
}
