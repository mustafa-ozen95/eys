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
 * Denetim kaydının hash zinciri.
 *
 * Amaç kaydı gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ kanıtlayabilmek. Bu testlerin
 * kilitlediği şey: bir satır değişirse ya da silinirse doğrulama bunu
 * yakalıyor ve kırılmanın yerini söylüyor mu.
 */
class ActivityChainTest extends TestCase
{
    use RefreshDatabase;

    private function yaz(string $ozet): ActivityLog
    {
        return app(ActivityRecorder::class)->record(
            ActivityAction::LoggedIn, $ozet, null, null, User::factory()->admin()->create(),
        );
    }

    #[Test]
    public function saglam_zincir_dogrulanir(): void
    {
        $this->yaz('bir');
        $this->yaz('iki');
        $this->yaz('uc');

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertTrue($sonuc['ok']);
        $this->assertSame(3, $sonuc['checked']);
        $this->assertSame(0, $sonuc['unprotected']);
        $this->assertNull($sonuc['broken_id']);
    }

    #[Test]
    public function her_satir_oncekinin_ozetini_tasir(): void
    {
        $ilk = $this->yaz('bir');
        $ikinci = $this->yaz('iki');

        $this->assertNotNull($ilk->hash);
        $this->assertSame($ilk->hash, $ikinci->previous_hash);
    }

    #[Test]
    public function degistirilen_satir_yakalanir_ve_yeri_bildirilir(): void
    {
        $this->yaz('bir');
        $hedef = $this->yaz('iki');
        $this->yaz('uc');

        /* Uygulamayı atlayarak doğrudan veritabanına müdahale. */
        DB::table('activity_logs')->where('id', $hedef->id)->update(['summary' => 'degistirildi']);

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertFalse($sonuc['ok']);
        $this->assertSame($hedef->id, $sonuc['broken_id'], 'Kırılmanın YERİ bildirilmeli.');
    }

    #[Test]
    public function silinen_satir_zinciri_kirar(): void
    {
        $this->yaz('bir');
        $hedef = $this->yaz('iki');
        $this->yaz('uc');

        DB::table('activity_logs')->where('id', $hedef->id)->delete();

        $this->assertFalse(app(ActivityChain::class)->verify()['ok']);
    }

    #[Test]
    public function uygulama_kendi_kaydini_degistiremez_ve_silemez(): void
    {
        $log = $this->yaz('bir');

        $log->summary = 'degistirildi';

        $this->assertFalse($log->save(), 'Güncelleme engellenmeli.');
        $this->assertFalse($log->delete(), 'Silme engellenmeli.');
        $this->assertSame('bir', $log->fresh()->summary);
    }

    #[Test]
    public function zincirden_once_yazilan_satirlar_dogrulanmis_gibi_gosterilmez(): void
    {
        /*
         * Özeti olmayan satırlar sonradan özetlenmez: değişmediklerine dair
         * elimizde kanıt yok ve öyleymiş gibi davranmak zincirin anlamını
         * ortadan kaldırırdı.
         */
        DB::table('activity_logs')->insert([
            'chain' => 'audit', 'actor_name' => 'eski', 'action' => 'logged_in',
            'summary' => 'zincirden once', 'created_at' => now(),
        ]);

        $this->yaz('zincirli');

        $sonuc = app(ActivityChain::class)->verify();

        $this->assertTrue($sonuc['ok']);
        $this->assertSame(1, $sonuc['checked']);
        $this->assertSame(1, $sonuc['unprotected']);
    }

    #[Test]
    public function anahtar_degisirse_zincir_kirilir(): void
    {
        $this->yaz('bir');

        /* Özet düz SHA değil HMAC: anahtar olmadan yeniden hesaplanamaz. */
        config(['activity.chain_key' => 'baska-bir-anahtar']);

        $this->assertFalse(app(ActivityChain::class)->verify()['ok']);
    }

    /**
     * Kullanıcı silmek, özetli satırların user_id'sini DB'ye NULL yaptırmamalı;
     * zincir kırılmamalı ve kim yaptı bilgisi kalmalı.
     */
    #[Test]
    public function kullanici_silmek_zinciri_kirmaz(): void
    {
        $kisi = User::factory()->create();
        $satir = app(ActivityRecorder::class)->record(ActivityAction::LoggedIn, 'kisinin isi', null, null, $kisi);
        $this->yaz('baska is');

        $kisi->delete();

        $this->assertSame($kisi->id, $satir->fresh()->user_id);
        $this->assertTrue(app(ActivityChain::class)->verify()['ok']);
    }
}
