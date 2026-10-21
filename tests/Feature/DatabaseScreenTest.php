<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Database\ConnectionFile;
use App\Services\Database\RemoteDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Veritabanı sekmesi: sına, sonra taşı.
 *
 * Asıl kurallar: sınanmamış bir hedefe taşınmaz, taşı düğmesi sınamaları
 * yeniden yapar, parola ne sayfada ne denetim kaydında görünür. Kopyanın
 * kendisi başlatıcıda yapılır; burada yalnızca isteğin doğru bırakıldığı
 * sınanır.
 */
class DatabaseScreenTest extends TestCase
{
    use RefreshDatabase;

    private const HEDEF = [
        'host' => 'db.ornek.local',
        'port' => 3306,
        'database' => 'syd_uzak',
        'username' => 'syd',
        'password' => 'Gizli-Parola-123',
    ];

    private string $dizin;

    private ConnectionFile $dosya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/veritabani-'.bin2hex(random_bytes(4));
        $this->dosya = new ConnectionFile(app('encrypter'), $this->dizin);
        $this->instance(ConnectionFile::class, $this->dosya);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dizin.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dizin);

        parent::tearDown();
    }

    /**
     * Ağa çıkmayan sahte: sınamaların sonucu sırayla verilir.
     *
     * @param  list<bool>  $sirayla  her sina() çağrısında hepsi geçiyor mu
     */
    private function sahte(array $sirayla = [true], string $kaynak = 'ortam'): RemoteDatabase
    {
        $sahte = new class($this->dosya, $sirayla, $kaynak) extends RemoteDatabase
        {
            public int $sinama = 0;

            public function __construct(ConnectionFile $dosya, private array $sirayla, private string $kaynak)
            {
                parent::__construct($dosya);
            }

            public function bilgi(): array
            {
                return ['kaynak' => $this->kaynak, 'host' => 'db', 'port' => '3306', 'database' => 'syd',
                    'username' => 'syd', 'surum' => '10.11.14-MariaDB', 'boyut' => 2_500_000, 'tablo' => 48, 'ozel' => 0];
            }

            public function sina(array $hedef): array
            {
                $gecti = $this->sirayla[min($this->sinama++, count($this->sirayla) - 1)];

                return [
                    ['ad' => 'Bağlantı', 'tamam' => true, 'ayrinti' => $hedef['username'].'@'.$hedef['host']],
                    ['ad' => 'Hedef veritabanı boş', 'tamam' => $gecti, 'ayrinti' => $gecti ? 'Tablo yok.' : '3 tablo var; dolu bir veritabanının üzerine yazılmaz.'],
                ];
            }
        };

        $this->instance(RemoteDatabase::class, $sahte);

        return $sahte;
    }

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    #[Test]
    public function ekran_yalnizca_ayar_yetkisi_olana_acilir(): void
    {
        $sahte = $this->sahte();

        $this->actingAs(User::factory()->create())
            ->get(route('veritabani.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('veritabani.sina'), self::HEDEF)
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('veritabani.tasi'))
            ->assertForbidden();

        $this->assertSame(0, $sahte->sinama, 'Yetkisiz istek sınama koşturmamalıydı.');
        $this->assertNull($this->dosya->durum());

        $this->actingAs($this->yonetici())
            ->get(route('veritabani.index'))
            ->assertOk()
            ->assertSee('Şu anki veritabanı')
            ->assertSee('Kurulum ayarı')
            ->assertSee('10.11.14-MariaDB')
            ->assertSee('Bağlantıyı sına');
    }

    #[Test]
    public function sinama_gecerse_tasi_dugmesi_gorunur_parola_gorunmez(): void
    {
        $this->sahte([true]);

        $this->actingAs($this->yonetici())
            ->from(route('veritabani.index'))
            ->followingRedirects()
            ->post(route('veritabani.sina'), self::HEDEF)
            ->assertOk()
            ->assertSee('Sınamalar geçti')
            ->assertSee('syd@db.ornek.local:3306/syd_uzak hedefine taşı')
            ->assertDontSee('Gizli-Parola-123');
    }

    #[Test]
    public function sinama_gecmezse_sebep_gorunur_tasi_dugmesi_gorunmez(): void
    {
        $this->sahte([false]);

        $this->actingAs($this->yonetici())
            ->from(route('veritabani.index'))
            ->followingRedirects()
            ->post(route('veritabani.sina'), self::HEDEF)
            ->assertOk()
            ->assertSee('3 tablo var')
            ->assertSee('geçmedi')
            ->assertDontSee('hedefine taşı');
    }

    #[Test]
    public function konteynerin_kendi_adresi_reddedilir(): void
    {
        $sahte = $this->sahte();

        $this->actingAs($this->yonetici())
            ->from(route('veritabani.index'))
            ->post(route('veritabani.sina'), [...self::HEDEF, 'host' => 'localhost'])
            ->assertSessionHasErrors('host');

        $this->assertSame(0, $sahte->sinama, 'Sınama koşmamalıydı.');
    }

    #[Test]
    public function sinanmadan_tasinmaz(): void
    {
        $this->sahte();

        $this->actingAs($this->yonetici())
            ->from(route('veritabani.index'))
            ->post(route('veritabani.tasi'))
            ->assertSessionHasErrors('veritabani');

        $this->assertNull($this->dosya->durum());
        $this->assertNull($this->dosya->baglanti());
    }

    #[Test]
    public function tasi_istegi_birakir_ve_yeniden_baslatma_ister(): void
    {
        $this->sahte([true]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)
            ->from(route('veritabani.index'))
            ->post(route('veritabani.sina'), self::HEDEF);

        $this->actingAs($yonetici)
            ->from(route('veritabani.index'))
            ->post(route('veritabani.tasi'), ['reason' => 'uzak sunucuya geçiş'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(self::HEDEF, $this->dosya->baglanti());
        $this->assertSame('tasinacak', $this->dosya->asama());
        $this->assertSame('syd@db.ornek.local:3306/syd_uzak', $this->dosya->durum()['hedef']);
        $this->assertSame($yonetici->name, $this->dosya->durum()['isteyen']);
        $this->assertFileExists($this->dizin.'/restart');

        $kayit = ActivityLog::query()->where('summary', 'like', 'Veritabanı taşıması istendi:%')->sole();
        $this->assertSame(ActivityAction::Updated, $kayit->action);
        $this->assertStringContainsString('syd@db.ornek.local:3306/syd_uzak', $kayit->summary);
        $this->assertSame('uzak sunucuya geçiş', $kayit->reason);
        $this->assertStringNotContainsString('Gizli-Parola-123', $kayit->summary.$kayit->reason);

        $this->actingAs($yonetici)
            ->get(route('veritabani.index'))
            ->assertSee('istendi, yeniden başlatma bekleniyor')
            ->assertDontSee('Bağlantıyı sına');
    }

    /** Sınama ile taşı arasında hedef dolmuş olabilir: taşı sınamaları baştan yapar. */
    #[Test]
    public function tasi_sinamalari_yeniden_yapar(): void
    {
        $sahte = $this->sahte([true, false]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('veritabani.index'))
            ->post(route('veritabani.sina'), self::HEDEF);

        $this->actingAs($yonetici)->from(route('veritabani.index'))
            ->post(route('veritabani.tasi'))
            ->assertSessionHasErrors('veritabani');

        $this->assertSame(2, $sahte->sinama);
        $this->assertNull($this->dosya->durum());
        $this->assertNull($this->dosya->baglanti());
        $this->assertFileDoesNotExist($this->dizin.'/restart');
        $this->assertSame(0, ActivityLog::query()->where('summary', 'like', 'Veritabanı taşıması istendi:%')->count());
    }

    #[Test]
    public function tamamlanmis_tasimadan_sonra_form_yok_sonuc_gorunur(): void
    {
        $this->sahte(kaynak: 'panel');
        $this->dosya->asamaYaz('tasinacak', null, ['hedef' => 'syd@db.ornek.local:3306/syd_uzak']);
        $this->dosya->asamaYaz('deneniyor', '48 tablo, 1200 satır kopyalandı; iki tarafta sayılar aynı.');
        $this->dosya->asamaYaz('etkin');

        $this->actingAs($this->yonetici())
            ->get(route('veritabani.index'))
            ->assertOk()
            ->assertSee('Uzak (panel ayarı)')
            ->assertSee('tamamlandı')
            ->assertSee('48 tablo, 1200 satır kopyalandı')
            ->assertDontSee('Bağlantıyı sına');
    }

    #[Test]
    public function basarisiz_tasimanin_sebebi_gorunur_ve_yeniden_denenebilir(): void
    {
        $this->sahte();
        $this->dosya->asamaYaz('tasinacak', null, ['hedef' => 'syd@db.ornek.local:3306/syd_uzak']);
        $this->dosya->asamaYaz('basarisiz', 'Uzak veritabanı boş değil (3 tablo); üzerine yazılmaz.');

        $this->actingAs($this->yonetici())
            ->get(route('veritabani.index'))
            ->assertOk()
            ->assertSee('yapılmadı')
            ->assertSee('Uzak veritabanı boş değil (3 tablo)')
            ->assertSee('Bağlantıyı sına');
    }

    #[Test]
    public function sekme_ayarlar_seridinde_gorunur(): void
    {
        $this->sahte();

        $this->actingAs($this->yonetici())
            ->get(route('sertifika.index'))
            ->assertSee(route('veritabani.index'));
    }
}
