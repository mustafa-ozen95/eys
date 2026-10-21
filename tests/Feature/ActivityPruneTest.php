<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Denetim kaydının budanması.
 *
 * Budama, kaydın var olma sebebine dokunan tek işlemdir. Bu testlerin
 * kilitlediği şey: budamadan sonra KALAN satırların zinciri hâlâ doğrulanıyor
 * mu, budamanın kendisi kayda geçiyor mu ve bu kapı yalnızca komuta mı açık.
 */
class ActivityPruneTest extends TestCase
{
    use RefreshDatabase;

    private function yaz(string $ozet): ActivityLog
    {
        return app(ActivityRecorder::class)->record(
            ActivityAction::LoggedIn, $ozet, null, null, User::factory()->admin()->create(),
        );
    }

    /** Zincirden önce yazılmış satır: uygulamayı atlayarak, özetsiz. */
    private function korumasizYaz(string $ozet): void
    {
        DB::table('activity_logs')->insert([
            'actor_name' => 'eski',
            'action' => ActivityAction::LoggedIn->value,
            'summary' => $ozet,
            'chain' => ActivityChain::AUDIT,
            'created_at' => now()->subYear(),
        ]);
    }

    #[Test]
    public function korumasiz_satirlar_budanir_ve_zincir_saglam_kalir(): void
    {
        $this->korumasizYaz('eski bir');
        $this->korumasizYaz('eski iki');
        $this->yaz('yeni bir');
        $this->yaz('yeni iki');

        $this->assertSame(2, app(ActivityChain::class)->verify()['unprotected']);

        $this->artisan('erisim:kayit-buda', ['--korumasiz' => true, '--force' => true])
            ->assertSuccessful();

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertSame(0, $sonuc['unprotected']);
        $this->assertTrue($sonuc['ok'], 'Korumasız satırlar zincirin dışındaydı; silinmeleri onu kırmamalı.');
        $this->assertDatabaseMissing('activity_logs', ['summary' => 'eski bir']);
        $this->assertDatabaseHas('activity_logs', ['summary' => 'yeni bir']);
    }

    #[Test]
    public function budama_kendisi_kayda_gecer(): void
    {
        $this->korumasizYaz('eski');

        $this->artisan('erisim:kayit-buda', [
            '--korumasiz' => true,
            '--gerekce' => 'Denetim oncesi temizlik',
            '--force' => true,
        ])->assertSuccessful();

        $log = ActivityLog::query()->where('action', ActivityAction::Pruned->value)->firstOrFail();

        $this->assertSame('Denetim kaydından 1 satır silindi.', $log->summary);
        $this->assertSame('Denetim oncesi temizlik', $log->reason);
        $this->assertNotNull($log->hash, 'Budama kaydı da zincire girmeli.');
    }

    #[Test]
    public function saklama_suresi_dolan_satirlar_budanir_ve_zincir_capaya_baglanir(): void
    {
        $this->travelTo(now()->subDays(400));
        $eski = $this->yaz('cok eski');
        $this->travelBack();

        $kalan = $this->yaz('yeni');

        $this->artisan('erisim:kayit-buda', ['--gun' => '365', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['id' => $eski->id]);

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertTrue(
            $sonuc['ok'],
            'Zincirin başı kesildi ama çapa yazıldı: kalan satırlar hâlâ doğrulanmalı.',
        );
        $this->assertSame($eski->hash, $kalan->fresh()->previous_hash);
    }

    /** Budama satırı kayıt ekranında da okunabilmeli: yeni işlem türü ekrana bağlı. */
    #[Test]
    public function budama_satiri_kayit_ekraninda_gorunur(): void
    {
        $this->korumasizYaz('eski');

        $this->artisan('erisim:kayit-buda', ['--korumasiz' => true, '--force' => true])
            ->assertSuccessful();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Kayıt budandı')
            ->assertSee('Denetim kaydından 1 satır silindi.');
    }

    #[Test]
    public function olcut_verilmeden_hicbir_sey_silinmez(): void
    {
        $this->yaz('duruyor');

        $this->artisan('erisim:kayit-buda', ['--force' => true])->assertFailed();

        $this->assertDatabaseCount('activity_logs', 1);
    }

    /**
     * Budama komutu dışında silme YOKTUR.
     *
     * Toplu silme model olaylarını atlar; kapı yalnızca ActivityLog::prune()
     * içinde açılır, dışarıda kapalı kalır.
     */
    #[Test]
    public function komut_disinda_denetim_satiri_silinemez(): void
    {
        $log = $this->yaz('duruyor');

        $this->assertFalse($log->delete());
        $this->assertSame(0, ActivityLog::query()->whereKey($log->getKey())->delete());
        $this->assertDatabaseHas('activity_logs', ['id' => $log->id]);
    }
}
