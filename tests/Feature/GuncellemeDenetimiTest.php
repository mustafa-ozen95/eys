<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Guncelleme\GuncellemeDenetimi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Güncelleme kontrolü: GitHub'a ağa çıkmadan, sahte yanıtlarla.
 *
 * Asıl kurallar: karşılaştırma son BAŞARILI derlemeyle yapılır, yön ölçülür
 * (ileride olan "güncelleme" sayılmaz), jeton sonuçta ve iletilerde geçmez,
 * jeton yoksa açık depoya jetonsuz sorulur. Çalışan sürüm, derlemeye commit
 * vermeyen platformlarda bağlamdaki .git'ten okunur (docker/surum-oku.sh).
 */
class GuncellemeDenetimiTest extends TestCase
{
    private const JETON = 'github_pat_SINAMA_JETONU_123';

    private const CALISAN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SON = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $dizin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/guncelleme-'.bin2hex(random_bytes(4));
        config([
            'guncelleme.depo' => 'mustafa-ozen95/ornek',
            'guncelleme.jeton' => self::JETON,
            'guncelleme.surum' => self::CALISAN,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dizin.'/durum.json');
        @rmdir($this->dizin);

        parent::tearDown();
    }

    private function denetim(): GuncellemeDenetimi
    {
        return new GuncellemeDenetimi($this->dizin);
    }

    /**
     * @param  array<string, mixed>|null  $karsilastirma  compare yanıtı; null ise istenmemeli
     */
    private function sahte(string $son = self::SON, ?array $karsilastirma = null, int $kod = 200, string $mesaj = 'Bad credentials'): void
    {
        Http::fake([
            'api.github.com/repos/mustafa-ozen95/ornek/actions/workflows/imaj.yml/runs*' => Http::response(
                $kod === 200 ? ['workflow_runs' => [['head_sha' => $son, 'updated_at' => '2026-10-20T10:00:00Z']]] : ['message' => $mesaj],
                $kod,
            ),
            'api.github.com/repos/mustafa-ozen95/ornek/compare/*' => Http::response($karsilastirma ?? ['status' => 'identical']),
        ]);
    }

    /** @return array<string, mixed> */
    private function commit(string $sha, string $mesaj, string $tarih): array
    {
        return ['sha' => $sha, 'commit' => ['message' => $mesaj, 'committer' => ['date' => $tarih]]];
    }

    public function test_jeton_yoksa_acik_depoya_jetonsuz_sorulur(): void
    {
        config(['guncelleme.jeton' => '']);
        $this->sahte(son: self::CALISAN);

        $sonuc = $this->denetim()->denetle('sınama');

        $this->assertSame('guncel', $sonuc['durum']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => ! $r->hasHeader('Authorization'));
    }

    public function test_jetonsuz_sorulan_ozel_ya_da_olmayan_depo_anlasilir_hata_verir(): void
    {
        config(['guncelleme.jeton' => '']);
        $this->sahte(kod: 404, mesaj: 'Not Found');

        $sonuc = $this->denetim()->denetle('sınama');

        $this->assertSame('hata', $sonuc['durum']);
        $this->assertStringContainsString('404', $sonuc['aciklama']);
        $this->assertStringContainsString('GUNCELLEME_JETONU', $sonuc['aciklama']);
    }

    /** Ayrı ref dosyası, packed-refs, bozuk HEAD ve hiç .git olmaması. */
    public function test_calisan_surum_derleme_baglamindaki_git_ten_okunur(): void
    {
        $kok = sys_get_temp_dir().'/surum-'.bin2hex(random_bytes(4));
        $oku = fn (): string => trim(Process::run(['sh', base_path('docker/surum-oku.sh'), $kok])->throw()->output());

        try {
            $this->assertSame('', $oku());

            mkdir($kok.'/.git/refs/heads', 0o700, true);
            file_put_contents($kok.'/.git/HEAD', "ref: refs/heads/main\n");
            file_put_contents($kok.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n".self::SON." refs/heads/main\n");
            $this->assertSame(self::SON, $oku());

            file_put_contents($kok.'/.git/refs/heads/main', self::CALISAN."\n");
            $this->assertSame(self::CALISAN, $oku());

            file_put_contents($kok.'/.git/HEAD', "bozuk\n");
            $this->assertSame('', $oku());
        } finally {
            Process::run(['rm', '-rf', $kok]);
        }
    }

    public function test_son_basarili_derlemeyle_ayniysa_guncel(): void
    {
        $this->sahte(son: self::CALISAN);

        $sonuc = $this->denetim()->denetle('sınama');

        $this->assertSame('guncel', $sonuc['durum']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.self::JETON)
            && str_contains($r->url(), 'branch=main')
            && str_contains($r->url(), 'status=success'));
    }

    public function test_geride_ise_yeni_surum_ve_commitler_yeniden_eskiye(): void
    {
        $this->sahte(karsilastirma: [
            'status' => 'ahead', 'ahead_by' => 2, 'behind_by' => 0,
            'commits' => [
                $this->commit('c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1', "Eski düzeltme\n\nayrıntı", '2026-10-19T08:00:00Z'),
                $this->commit(self::SON, 'Yeni özellik', '2026-10-20T09:00:00Z'),
            ],
        ]);

        $denetim = $this->denetim();
        $sonuc = $denetim->denetle('sınama');

        $this->assertSame('yeni', $sonuc['durum']);
        $this->assertSame(2, $sonuc['geride']);
        $this->assertSame(['Yeni özellik', 'Eski düzeltme'], array_column($sonuc['commitler'], 'baslik'));
        $this->assertTrue($denetim->yeniVar());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/compare/'.self::CALISAN.'...'.self::SON));

        // Panel güncellendi: eski sonuç yeni sürümü anlatmıyor, uyarı susar.
        config(['guncelleme.surum' => self::SON]);
        $this->assertFalse($denetim->yeniVar());
    }

    public function test_ileride_ise_guncelleme_denmez(): void
    {
        $this->sahte(karsilastirma: ['status' => 'behind', 'ahead_by' => 0, 'behind_by' => 3, 'commits' => []]);

        $denetim = $this->denetim();
        $sonuc = $denetim->denetle('sınama');

        $this->assertSame('ileride', $sonuc['durum']);
        $this->assertStringContainsString('geri almak', $sonuc['aciklama']);
        $this->assertFalse($denetim->yeniVar());
    }

    public function test_ayrisik_dal(): void
    {
        $this->sahte(karsilastirma: ['status' => 'diverged', 'ahead_by' => 1, 'behind_by' => 2, 'commits' => []]);

        $this->assertSame('ayrisik', $this->denetim()->denetle('sınama')['durum']);
    }

    public function test_calisan_surum_bilinmiyorsa_karsilastirilmaz(): void
    {
        config(['guncelleme.surum' => '']);
        $this->sahte();

        $sonuc = $this->denetim()->denetle('sınama');

        $this->assertSame('bilinmiyor', $sonuc['durum']);
        $this->assertSame(self::SON, $sonuc['son']);
        Http::assertSentCount(1);
    }

    public function test_reddedilen_jeton_hata_olur_ve_jeton_yazilmaz(): void
    {
        $this->sahte(kod: 401);

        $sonuc = $this->denetim()->denetle('sınama');

        $this->assertSame('hata', $sonuc['durum']);
        $this->assertStringContainsString('401', $sonuc['aciklama']);
        $this->assertStringNotContainsString(self::JETON, (string) file_get_contents($this->dizin.'/durum.json'));
    }

    public function test_erisim_yoksa_hata_ve_son_sonuc_okunur(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $denetim = $this->denetim();
        $denetim->denetle('sınama');

        $son = $denetim->son();
        $this->assertSame('hata', $son['durum']);
        $this->assertStringContainsString('erişilemedi', $son['aciklama']);
        $this->assertSame('sınama', $son['tetikleyen']);
        $this->assertSame(self::CALISAN, $son['calisan']);
    }

    public function test_bozuk_sonuc_dosyasi_yok_sayilir(): void
    {
        @mkdir($this->dizin, 0700, true);
        file_put_contents($this->dizin.'/durum.json', '{"durum":"bilmem"}');

        $this->assertNull($this->denetim()->son());
        $this->assertFalse($this->denetim()->yeniVar());
    }
}
