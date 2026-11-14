<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\ActivityAction;
use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Jobs\ApplyOsUpdatesJob;
use App\Jobs\CheckOsUpdatesJob;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\OsUpdateState;
use App\Models\User;
use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateScripts;
use App\Services\OsUpdate\OsUpdateShell;
use App\Services\OsUpdate\OsUpdateTargets;
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

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;

        $this->app->instance(OsUpdateShell::class, new class($test) extends OsUpdateShell
        {
            public function __construct(private OsUpdateTest $test) {}

            public function calistir(Credential $credential, string $betik, int $sure, string $hata): string
            {
                return $this->test->sahteCalistir($betik);
            }
        });
    }

    public function sahteCalistir(string $betik): string
    {
        $this->calisan[] = $betik;

        return str_contains($betik, 'setsid')
            ? ''
            : "EYS-PM:apt\nEYS-BOLUM:liste\nopenssl/noble-security 3.1 amd64 [upgradable from: 3.0]\n"
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
        $win = $this->makine($group, 'Win', '10.0.2.1', ['operating_system' => OperatingSystem::Windows, 'access_type' => AccessType::Rdp]);
        $pasif = $this->makine($group, 'Pasif', '10.0.2.2', ['is_active' => false]);
        $kisisel = $this->makine($group, 'Kisisel', '10.0.2.3', ['owner_id' => $user->id]);

        $this->actingAs($user)
            ->post(route('os-updates.apply'), ['kayitlar' => [$win->uuid, $pasif->uuid, $kisisel->uuid], 'mod' => 'tumu', 'reason' => 'Deneme'])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();

        $this->actingAs($user)->get(route('os-updates.index'))
            ->assertDontSee('Win')->assertDontSee('Pasif')->assertDontSee('Kisisel');
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

        $log = ActivityLog::query()->where('action', ActivityAction::OsUpdate->value)->latest('id')->first();
        $this->assertSame('Aylık yama', $log->reason);
        $this->assertStringContainsString('yalnızca kritik', $log->summary);
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
}
