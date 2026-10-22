<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Update\UpdateCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ayarlar → Güncelleme ve yöneticinin uyarı şeridi.
 *
 * Asıl kurallar: ekran ve şerit yalnız yöneticide, düğme kontrolü hemen
 * yapar, jeton sayfada görünmez, panel güncellenince eski "yeni sürüm var"
 * sonucu susar.
 */
class UpdateScreenTest extends TestCase
{
    use RefreshDatabase;

    private const JETON = 'github_pat_SINAMA_JETONU_123';

    private const CALISAN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SON = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $dizin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/guncelleme-'.bin2hex(random_bytes(4));
        $this->instance(UpdateCheck::class, new UpdateCheck($this->dizin));
        config([
            'update.repo' => 'mustafa-ozen95/ornek',
            'update.token' => self::JETON,
            'update.version' => self::CALISAN,
        ]);

        Http::fake([
            'api.github.com/*/runs*' => Http::response(['workflow_runs' => [['head_sha' => self::SON, 'updated_at' => '2026-10-20T10:00:00Z']]]),
            'api.github.com/*/compare/*' => Http::response(['status' => 'ahead', 'ahead_by' => 3, 'behind_by' => 0, 'commits' => [
                ['sha' => self::SON, 'commit' => ['message' => 'Yeni özellik: güncelleme sekmesi', 'committer' => ['date' => '2026-10-20T09:00:00Z']]],
            ]]),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dizin.'/state.json');
        @rmdir($this->dizin);

        parent::tearDown();
    }

    public function test_ekran_yalnizca_yoneticide_acilir(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('update.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('update.check'))
            ->assertForbidden();
        Http::assertNothingSent();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('update.index'))
            ->assertOk()
            ->assertSee('aaaaaaa')
            ->assertSee('henüz yapılmadı')
            ->assertSee('Şimdi kontrol et');
    }

    public function test_dugme_kontrol_eder_commitler_gorunur_jeton_gorunmez(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)
            ->from(route('update.index'))
            ->post(route('update.check'))
            ->assertRedirect(route('update.index'))
            ->assertSessionHas('status');

        $this->actingAs($yonetici)
            ->get(route('update.index'))
            ->assertOk()
            ->assertSee('yeni sürüm var')
            ->assertSee('Yeni özellik: güncelleme sekmesi')
            ->assertSee('bbbbbbb')
            ->assertSee('ve 2 commit daha.')
            ->assertSee($yonetici->name)
            ->assertDontSee(self::JETON);
    }

    public function test_yeni_surum_uyarisi_yalnizca_yoneticide_ve_guncellenince_susar(): void
    {
        app(UpdateCheck::class)->denetle('günlük tur');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('Panelin yeni sürümü var.')
            ->assertSee('3 commit geride');

        $this->actingAs(User::factory()->create())
            ->get(route('account.profile'))
            ->assertOk()
            ->assertDontSee('Panelin yeni sürümü var.');

        config(['update.version' => self::SON]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('account.profile'))
            ->assertOk()
            ->assertDontSee('Panelin yeni sürümü var.');
    }

    public function test_kontrol_her_sabah_zamanlanmis(): void
    {
        $olay = collect(app(Schedule::class)->events())
            ->first(fn ($olay): bool => str_contains((string) $olay->command, 'update:check'));

        $this->assertNotNull($olay, 'update:check zamanlayıcıda yok.');
        $this->assertSame('50 6 * * *', $olay->expression);
    }

    public function test_kontrol_edilemezse_sebep_gorunur(): void
    {
        // Testler ağa çıkmaz: GitHub'a ulaşılamıyormuş gibi.
        Http::fake(fn () => throw new ConnectionException('ağ kapalı'));
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)
            ->from(route('update.index'))
            ->post(route('update.check'))
            ->assertSessionHas('error');

        $this->actingAs($yonetici)
            ->get(route('update.index'))
            ->assertSee('kontrol edilemedi')
            ->assertSee('erişilemedi');
    }
}
