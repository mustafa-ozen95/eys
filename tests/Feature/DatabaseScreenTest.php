<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Database\ConnectionFile;
use App\Services\Database\RemoteDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

            /** @var list<string> */
            public array $islemler = [];

            public function __construct(ConnectionFile $dosya, private array $sirayla, private string $kaynak)
            {
                parent::__construct($dosya);
            }

            public function bilgi(): array
            {
                return ['kaynak' => $this->kaynak, 'host' => 'db', 'port' => '3306', 'database' => 'syd',
                    'username' => 'syd', 'surum' => '10.11.14-MariaDB', 'boyut' => 2_500_000, 'tablo' => 48, 'ozel' => 0];
            }

            public function sina(array $hedef, string $islem = 'tasi'): array
            {
                $this->islemler[] = $islem;
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
            ->get(route('database.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('database.test'), self::HEDEF)
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->post(route('database.migrate'))
            ->assertForbidden();

        $this->assertSame(0, $sahte->sinama, 'Yetkisiz istek sınama koşturmamalıydı.');
        $this->assertNull($this->dosya->durum());

        $this->actingAs($this->yonetici())
            ->get(route('database.index'))
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
            ->from(route('database.index'))
            ->followingRedirects()
            ->post(route('database.test'), self::HEDEF)
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
            ->from(route('database.index'))
            ->followingRedirects()
            ->post(route('database.test'), self::HEDEF)
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
            ->from(route('database.index'))
            ->post(route('database.test'), [...self::HEDEF, 'host' => 'localhost'])
            ->assertSessionHasErrors('host');

        $this->assertSame(0, $sahte->sinama, 'Sınama koşmamalıydı.');
    }

    #[Test]
    public function sinanmadan_tasinmaz(): void
    {
        $this->sahte();

        $this->actingAs($this->yonetici())
            ->from(route('database.index'))
            ->post(route('database.migrate'))
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
            ->from(route('database.index'))
            ->post(route('database.test'), self::HEDEF);

        $this->actingAs($yonetici)
            ->from(route('database.index'))
            ->post(route('database.migrate'), ['reason' => 'uzak sunucuya geçiş'])
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
            ->get(route('database.index'))
            ->assertSee('istendi, yeniden başlatma bekleniyor')
            ->assertDontSee('Bağlantıyı sına');
    }

    /** Sınama ile taşı arasında hedef dolmuş olabilir: taşı sınamaları baştan yapar. */
    #[Test]
    public function tasi_sinamalari_yeniden_yapar(): void
    {
        $sahte = $this->sahte([true, false]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('database.index'))
            ->post(route('database.test'), self::HEDEF);

        $this->actingAs($yonetici)->from(route('database.index'))
            ->post(route('database.migrate'))
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
            ->get(route('database.index'))
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
            ->get(route('database.index'))
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
            ->get(route('certificate.index'))
            ->assertSee(route('database.index'));
    }

    #[Test]
    public function baglan_anahtar_onayi_ister(): void
    {
        $sahte = $this->sahte([true]);

        $this->actingAs($this->yonetici())
            ->from(route('database.index'))
            ->post(route('database.test'), [...self::HEDEF, 'islem' => 'baglan'])
            ->assertSessionHasErrors('anahtar_onay');

        $this->assertSame(0, $sahte->sinama, 'Onaysız sınama koşmamalıydı.');
    }

    /** Yeni kurulum eski kurulumun dolu veritabanına: kopya yok, aşama "baglanacak"; onay penceresi veritabanı adını ister. */
    #[Test]
    public function baglan_istegi_birakir_kopyalamaz(): void
    {
        $sahte = $this->sahte([true]);
        $yonetici = $this->yonetici();
        $adres = self::HEDEF['username'].'@'.self::HEDEF['host'].':'.self::HEDEF['port'].'/'.self::HEDEF['database'];

        $this->actingAs($yonetici)
            ->from(route('database.index'))
            ->followingRedirects()
            ->post(route('database.test'), [...self::HEDEF, 'islem' => 'baglan', 'anahtar_onay' => '1'])
            ->assertOk()
            ->assertSee('Bağlanmak için aşağıdaki düğmeyi kullanın')
            ->assertSee($adres.' veritabanına bağlan')
            ->assertSee('data-confirm-title="Mevcut veritabanına bağlan"', false)
            ->assertSee('data-confirm-verify="'.self::HEDEF['database'].'"', false)
            ->assertDontSee('hedefine taşı')
            ->assertDontSee(self::HEDEF['password']);

        $this->actingAs($yonetici)
            ->from(route('database.index'))
            ->post(route('database.migrate'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'Bağlanma istendi'));

        $this->assertSame(['baglan', 'baglan'], $sahte->islemler, 'Bağlan düğmesi de sınamaları bağlan olarak yeniden yapmalı.');
        $this->assertSame(self::HEDEF, $this->dosya->baglanti());
        $this->assertSame('baglanacak', $this->dosya->asama());
        $this->assertSame('baglan', $this->dosya->durum()['islem']);
        $this->assertFileExists($this->dizin.'/restart');
        $kayit = ActivityLog::query()->where('summary', 'like', 'Mevcut veritabanına bağlanma istendi:%')->sole();
        $this->assertStringContainsString($adres, $kayit->summary);
        $this->assertStringNotContainsString(self::HEDEF['password'], $kayit->summary);

        $this->actingAs($yonetici)
            ->get(route('database.index'))
            ->assertSee('Son bağlanma')
            ->assertSee('bağlanma istendi, yeniden başlatma bekleniyor')
            ->assertDontSee('Bağlantıyı sına');
    }

    /** Taşı da onay penceresiyle: veritabanı adı yazılmadan gönderilmez. */
    #[Test]
    public function tasi_varsayilan_islem_ve_onay_penceresi(): void
    {
        $sahte = $this->sahte([true]);

        $this->actingAs($this->yonetici())
            ->from(route('database.index'))
            ->followingRedirects()
            ->post(route('database.test'), self::HEDEF)
            ->assertSee('data-confirm-title="Veritabanını taşı"', false)
            ->assertSee('data-confirm-verify="'.self::HEDEF['database'].'"', false);

        $this->assertSame(['tasi'], $sahte->islemler);
    }

    /** Bağlanılacak hedef bu panelin olmalı ve bu sürümden yeni olmamalı. */
    #[Test]
    public function goc_karsilastirmasi(): void
    {
        $yerel = ['2026_01_01_a', '2026_01_02_b', '2026_01_03_c'];

        $this->assertSame(['ortak' => 3, 'bilinmeyen' => 0, 'bekleyen' => 0], RemoteDatabase::gocKarsilastir($yerel, $yerel));
        $this->assertSame(['ortak' => 2, 'bilinmeyen' => 0, 'bekleyen' => 1], RemoteDatabase::gocKarsilastir(['2026_01_01_a', '2026_01_02_b'], $yerel));
        $this->assertSame(['ortak' => 3, 'bilinmeyen' => 1, 'bekleyen' => 0], RemoteDatabase::gocKarsilastir([...$yerel, '2026_02_01_yeni'], $yerel));
        $this->assertSame(['ortak' => 0, 'bilinmeyen' => 2, 'bekleyen' => 3], RemoteDatabase::gocKarsilastir(['baska_a', 'baska_b'], $yerel));
    }

    /** Yerel liste "migrate"in gördüğüyle aynı: çalışan şema bu sürümden yeni sayılmamalı. */
    #[Test]
    public function yerel_goc_listesi_calisan_semayla_ortusur(): void
    {
        $yerel = (fn (): array => $this->yerelGocler())->call(app(RemoteDatabase::class));
        $calisan = DB::table('migrations')->pluck('migration')->all();

        $this->assertSame(['ortak' => count($calisan), 'bilinmeyen' => 0, 'bekleyen' => 0], RemoteDatabase::gocKarsilastir($calisan, $yerel));
    }
}
