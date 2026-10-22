<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zincirin mühürlü çapası ve ucu.
 *
 * (1) Çapa veritabanında mühürlüdür: yeniden başlatma sağlam zinciri kırık
 * göstermez ve sonraki gerçek kurcalamayı maskelemez; (2) doğrulama zincirin
 * SONUNDAKİ silinmeyi, zincir dışı türde sokulan satırı, user_agent
 * değişikliğini ve alan sınırı kaydırmayı da görür.
 */
class ActivityChainSealTest extends TestCase
{
    use RefreshDatabase;

    private function yaz(string $ozet, ?string $neden = null): ActivityLog
    {
        return app(ActivityRecorder::class)->record(
            ActivityAction::LoggedIn, $ozet, null, $neden, User::factory()->admin()->create(),
        );
    }

    #[Test]
    public function capa_onbellek_silinse_de_kaybolmaz(): void
    {
        $this->travelTo(now()->subDays(400));
        $this->yaz('cok eski');
        $this->travelBack();
        $this->yaz('yeni bir');
        $this->yaz('yeni iki');

        $this->artisan('activity:prune', ['--days' => '365', '--force' => true])->assertSuccessful();
        $this->assertTrue(app(ActivityChain::class)->verify()['ok']);

        /* Gömülü redis yeniden başladı: önbellek BOŞ. */
        Cache::flush();

        $sonuc = app(ActivityChain::class)->verify();
        $this->assertTrue($sonuc['ok'], 'Çapa veritabanında; önbellek gidince zincir kırık görünmemeli.');

        /* Sonraki gerçek kurcalama yine yakalanır (maskelenmez). */
        $hedef = ActivityLog::query()->where('summary', 'yeni iki')->firstOrFail();
        DB::table('activity_logs')->where('id', $hedef->id)->update(['summary' => 'degistirildi']);
        $sonuc = app(ActivityChain::class)->verify();
        $this->assertFalse($sonuc['ok']);
        $this->assertSame($hedef->id, $sonuc['broken_id']);
    }

    #[Test]
    public function canlidaki_eski_satirlar_muhur_olmadan_da_dogrulanir_ve_zincir_surer(): void
    {
        /* Mühürden ÖNCE yazılmış, sürüm 1 özetli iki satır (uygulamayı atlayarak). */
        $onceki = null;
        foreach (['eski bir', 'eski iki'] as $i => $ozet) {
            $zaman = now()->subDays(3 - $i)->format('Y-m-d H:i:s.u');
            $payload = implode('|', [$onceki ?? '', $zaman, '', 'sistem', '', '', 'logged_in', $ozet, '', '']);
            $hash = hash_hmac('sha256', $payload, (string) config('activity.chain_key'));
            DB::table('activity_logs')->insert([
                'chain' => 'audit', 'chain_version' => 1, 'actor_name' => 'sistem', 'action' => 'logged_in',
                'summary' => $ozet, 'created_at' => $zaman, 'previous_hash' => $onceki, 'hash' => $hash,
            ]);
            $onceki = $hash;
        }
        $this->assertSame(0, DB::table('activity_chain_state')->count(), 'Kurgu: mühür henüz yok.');

        $this->assertSame(2, app(ActivityChain::class)->verify()['checked']);

        $this->yaz('yeni');
        $sonuc = app(ActivityChain::class)->verify();

        $this->assertTrue($sonuc['ok']);
        $this->assertSame(3, $sonuc['checked']);
        $this->assertSame(1, DB::table('activity_chain_state')->count());
    }

    #[Test]
    public function zincirin_sonundaki_silinme_yakalanir(): void
    {
        $this->yaz('bir');
        $this->yaz('iki');
        $son = $this->yaz('uc');

        DB::table('activity_logs')->where('id', $son->id)->delete();

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertNotNull($sonuc['detail']);
    }

    #[Test]
    public function zincir_disi_turde_sokulan_satir_yakalanir(): void
    {
        $this->yaz('bir');
        DB::table('activity_logs')->insert([
            'chain' => 'x', 'actor_name' => 'sahte', 'action' => 'logged_in', 'summary' => 'uydurma', 'created_at' => now(),
        ]);

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertNotNull($sonuc['broken_id']);
    }

    #[Test]
    public function user_agent_degisikligi_yakalanir(): void
    {
        $this->yaz('bir');
        $hedef = $this->yaz('iki');
        DB::table('activity_logs')->where('id', $hedef->id)->update(['user_agent' => 'baskasi']);

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($hedef->id, $sonuc['broken_id']);
    }

    #[Test]
    public function alan_siniri_kaydirma_yakalanir(): void
    {
        $this->yaz('bir');
        $hedef = $this->yaz('a|b', 'c');

        /* Eski '|' birleştirmesinde bu iki satır AYNI özeti üretirdi. */
        DB::table('activity_logs')->where('id', $hedef->id)->update(['summary' => 'a', 'reason' => 'b|c']);

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($hedef->id, $sonuc['broken_id']);
    }

    #[Test]
    public function muhur_kurcalanirsa_yakalanir(): void
    {
        $this->yaz('bir');
        $this->yaz('iki');
        $this->assertTrue(app(ActivityChain::class)->verify()['ok']);

        /* Anahtarsız biri sayacı/ucu değiştirip son satırı gizlemeye çalışır. */
        DB::table('activity_chain_state')->update(['rows' => 1]);

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertStringContainsString('mührü', (string) $sonuc['detail']);
    }
}
