<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Panelin veritabanı: şu an nerede, ve uzak bir hedef taşımaya ya da
 * bağlanmaya uygun mu?
 *
 * İKİ İŞLEM: TAŞI boş bir hedefe şu anki veritabanını kopyalar; BAĞLAN
 * kopyalamadan DOLU bir hedefe geçer (yeni kurulumu eski kurulumun
 * veritabanına bağlamak). Bağlanılacak hedef bu panelin olmalı (göç kayıtları
 * tutmalı) ve bu sürümden yeni olmamalı: eski kod yeni şemayla açılırsa
 * veriyi bozabilir.
 *
 * Taşımanın kendisi başlatıcıda yapılır (bkz. <panel>-entrypoint, "Paneldeki
 * veritabanı ayarı" bölümü); bu sınıf yalnızca ÖNCESİNİ sınar. Aynı
 * sınamaları başlatıcı kopyadan önce bir kez daha yapar: sınama ile yeniden
 * başlatma arasında hedef değişebilir.
 *
 * Yetki SORULMAZ, DENENİR: GRANT çıktısını okumak MariaDB ile MySQL arasında,
 * rol ve joker veritabanı adlarıyla ayrışıyor. Göçlerin yaptığı işi (tablo,
 * dizin, yabancı anahtar, değişiklik, silme) küçük bir tabloda yapıp
 * kaldırmak, "yetecek mi" sorusunun tek kesin cevabı.
 */
class RemoteDatabase
{
    /** Laravel'in desteklediği en eski sürümler. */
    private const EN_ESKI = ['MariaDB' => '10.3', 'MySQL' => '5.7'];

    private const SINAMA_TABLOLARI = ['dc_baglanti_sinamasi_b', 'dc_baglanti_sinamasi_a'];

    public function __construct(private ConnectionFile $dosya) {}

    /**
     * Şu anki veritabanı.
     *
     * @return array{kaynak: string, host: string, port: string, database: string, username: string,
     *               surum: ?string, boyut: ?int, tablo: ?int, ozel: ?int}
     */
    public function bilgi(): array
    {
        $ayar = (array) config('database.connections.'.config('database.default'));
        $host = (string) ($ayar['host'] ?? '');

        // ortam: kurulumun verdiği (DB_*; varsayılan compose'taki db servisi).
        // panel: bu sekmeden taşınmış, bağlantı dosyası ortamı eziyor.
        $kaynak = $this->dosya->asama() === 'etkin' ? 'panel' : 'ortam';

        $bilgi = [
            'kaynak' => $kaynak,
            'host' => $host,
            'port' => (string) ($ayar['port'] ?? ''),
            'database' => (string) ($ayar['database'] ?? ''),
            'username' => (string) ($ayar['username'] ?? ''),
            'surum' => null,
            'boyut' => null,
            'tablo' => null,
            'ozel' => null,
        ];

        try {
            $bilgi['surum'] = (string) DB::scalar('SELECT VERSION()');
            $satir = DB::selectOne('SELECT COUNT(*) AS tablo, COALESCE(SUM(data_length + index_length), 0) AS boyut
                FROM information_schema.tables WHERE table_schema = DATABASE()');
            $bilgi['tablo'] = (int) $satir->tablo;
            $bilgi['boyut'] = (int) $satir->boyut;
            $bilgi['ozel'] = $this->ozelNesneler();
        } catch (Throwable) {
            // Ekran yine açılır; değerler "bilinmiyor" görünür.
        }

        return $bilgi;
    }

    /**
     * Taşımadan (ya da bağlanmadan) önceki sınamalar. Sıra önemli:
     * bağlanamayan hedefte sonrakiler denenmez.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $hedef
     * @param  'tasi'|'baglan'  $islem
     * @return list<array{ad: string, tamam: bool, ayrinti: string}>
     */
    public function sina(array $hedef, string $islem = 'tasi'): array
    {
        $baglan = $islem === 'baglan';
        $sonuc = [];
        $kaynak = $this->bilgi();

        $adres = $kaynak['host'].':'.$kaynak['port'].'/'.$kaynak['database'];
        $sonuc[] = $this->madde(
            'Panel kurulumun veritabanında',
            $kaynak['kaynak'] === 'ortam',
            $kaynak['kaynak'] === 'ortam'
                ? ($baglan ? 'Şu anki veritabanı ('.$adres.') bırakılacak; kopyalanmaz.' : 'Kopya şu anki veritabanından ('.$adres.') alınacak.')
                : 'Panel zaten bu sekmeden taşınmış bir veritabanında ('.$adres.'); ikinci kez taşınmaz.',
        );

        $ayni = strtolower($hedef['host']) === strtolower($kaynak['host'])
            && (string) $hedef['port'] === $kaynak['port'] && $hedef['database'] === $kaynak['database'];
        $sonuc[] = $this->madde(
            'Hedef başka bir veritabanı',
            ! $ayni,
            $ayni ? 'Hedef, panelin şu anki veritabanının kendisi.' : 'Şu anki veritabanından farklı.',
        );

        if (! $baglan) {
            $sonuc[] = $this->madde(
                'Şu anki veritabanında yalnızca tablo var',
                $kaynak['ozel'] === 0,
                match ($kaynak['ozel']) {
                    0 => 'Görünüm, yordam, tetikleyici ya da zamanlanmış olay yok.',
                    null => 'Şu anki veritabanı okunamadı.',
                    default => $kaynak['ozel'].' görünüm/yordam/tetikleyici/olay var; taşıma bunları kopyalamaz.',
                },
            );
        }

        if ($ayni) {
            return $sonuc;
        }

        try {
            $pdo = $this->baglan($hedef);
        } catch (Throwable $e) {
            $sonuc[] = $this->madde('Bağlantı', false, self::temizMesaj($e));

            return $sonuc;
        }

        $sonuc[] = $this->madde('Bağlantı', true, $hedef['username'].'@'.$hedef['host'].':'.$hedef['port'].'/'.$hedef['database']);

        $surum = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $sonuc[] = $this->surumMaddesi($surum);

        $tablo = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();

        if ($baglan) {
            return [...$sonuc, ...$this->baglanMaddeleri($pdo, $tablo)];
        }

        $sonuc[] = $this->madde(
            'Hedef veritabanı boş',
            $tablo === 0,
            $tablo === 0 ? 'Tablo yok.' : $tablo.' tablo var; dolu bir veritabanının üzerine yazılmaz.',
        );

        if ($tablo === 0) {
            $sonuc[] = $this->yetkiMaddesi($pdo);
        }

        return $sonuc;
    }

    /**
     * Bağlanılacak hedef: dolu, bu panelin ve bu sürümden yeni değil. Yetki
     * de sınanır: açılışta bekleyen göçler hedefte koşacak.
     *
     * @return list<array{ad: string, tamam: bool, ayrinti: string}>
     */
    private function baglanMaddeleri(PDO $pdo, int $tablo): array
    {
        $gocTablosu = $tablo > 0 && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'migrations'")->fetchColumn() > 0;
        $hedefGocleri = $gocTablosu ? array_map('strval', $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN)) : [];
        $fark = self::gocKarsilastir($hedefGocleri, $this->yerelGocler());

        $sonuc = [$this->madde(
            'Hedef bu panelin dolu veritabanı',
            $fark['ortak'] > 0,
            match (true) {
                $tablo === 0 => 'Hedef boş: bağlanacak veri yok. Boş bir hedefe "Taşı" ile kopyalayın.',
                ! $gocTablosu => $tablo.' tablo var ama göç kaydı (migrations) yok; bu panelin veritabanı değil.',
                $fark['ortak'] === 0 => 'Göç kayıtları bu panele ait değil; başka bir uygulamanın veritabanı olabilir.',
                default => $tablo.' tablo; bu panelin '.$fark['ortak'].' göçü kayıtlı.',
            },
        )];

        if ($fark['ortak'] === 0) {
            return $sonuc;
        }

        $sonuc[] = $this->madde(
            'Hedef bu sürümden yeni değil',
            $fark['bilinmeyen'] === 0,
            $fark['bilinmeyen'] === 0
                ? ($fark['bekleyen'] > 0 ? $fark['bekleyen'].' göç açılışta hedefte uygulanacak.' : 'Şema bu sürümle aynı.')
                : 'Hedefte bu sürümün bilmediği '.$fark['bilinmeyen'].' göç var: daha yeni bir panel sürümüne ait. Önce bu paneli güncelleyin.',
        );
        $sonuc[] = $this->yetkiMaddesi($pdo);

        return $sonuc;
    }

    /**
     * Hedefteki göç kayıtları ile bu sürümün göç dosyaları.
     *
     * @param  list<string>  $hedef
     * @param  list<string>  $yerel
     * @return array{ortak: int, bilinmeyen: int, bekleyen: int}
     */
    public static function gocKarsilastir(array $hedef, array $yerel): array
    {
        return [
            'ortak' => count(array_intersect($hedef, $yerel)),
            'bilinmeyen' => count(array_diff($hedef, $yerel)),
            'bekleyen' => count(array_diff($yerel, $hedef)),
        ];
    }

    /**
     * Bu sürümün göç adları. Klasöre bakmak yetmez: modüller kendi göçlerini
     * ayrıca yükler (loadMigrationsFrom; ör. konsol modülü); "migrate"in
     * gördüğü listenin aynısı Laravel'in göç sisteminden alınır.
     *
     * @return list<string>
     */
    protected function yerelGocler(): array
    {
        $gocler = app('migrator');

        return array_keys($gocler->getMigrationFiles([database_path('migrations'), ...$gocler->paths()]));
    }

    /** @param  list<array{tamam: bool}>  $sonuc */
    public static function hepsiTamam(array $sonuc): bool
    {
        return $sonuc !== [] && collect($sonuc)->every(fn (array $m): bool => $m['tamam']);
    }

    public static function icMi(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['', '127.0.0.1', 'localhost', '::1'], true);
    }

    /** @param  array{host: string, port: int, database: string, username: string, password: string}  $h */
    protected function baglan(array $h): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $h['host'], $h['port'], $h['database']),
            $h['username'],
            $h['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
    }

    protected function ozelNesneler(): int
    {
        return (int) DB::scalar('SELECT
            (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE())');
    }

    /** @return array{ad: string, tamam: bool, ayrinti: string} */
    private function surumMaddesi(string $surum): array
    {
        $tur = stripos($surum, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        preg_match('/^\d+\.\d+(\.\d+)?/', $surum, $m);
        $yeterli = isset($m[0]) && version_compare($m[0], self::EN_ESKI[$tur], '>=');

        return $this->madde(
            'Sürüm',
            $yeterli,
            $tur.' '.$surum.($yeterli ? '' : ' — en az '.$tur.' '.self::EN_ESKI[$tur].' gerekir.'),
        );
    }

    /**
     * Göçlerin ihtiyaç duyduğu işler küçük bir tabloda denenir ve tablolar
     * HER DURUMDA kaldırılır: hedef "boş" sınamasından geçmişti, öyle kalmalı.
     *
     * @return array{ad: string, tamam: bool, ayrinti: string}
     */
    private function yetkiMaddesi(PDO $pdo): array
    {
        [$b, $a] = self::SINAMA_TABLOLARI;
        $adim = 'tablo açma';

        try {
            $pdo->exec("CREATE TABLE `$a` (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $adim = 'yabancı anahtar';
            $pdo->exec("CREATE TABLE `$b` (id INT UNSIGNED NOT NULL PRIMARY KEY, a_id INT UNSIGNED NOT NULL,
                CONSTRAINT `{$b}_fk` FOREIGN KEY (a_id) REFERENCES `$a` (id) ON DELETE CASCADE) ENGINE=InnoDB");
            $adim = 'dizin';
            $pdo->exec("CREATE INDEX `{$a}_ad` ON `$a` (ad)");
            $adim = 'tablo değiştirme';
            $pdo->exec("ALTER TABLE `$a` ADD COLUMN ek INT NULL");
            $adim = 'yazma';
            $pdo->exec("INSERT INTO `$a` (id, ad) VALUES (1, 'sınama')");
            $pdo->exec("INSERT INTO `$b` (id, a_id) VALUES (1, 1)");
            $adim = 'güncelleme';
            $pdo->exec("UPDATE `$a` SET ek = 1 WHERE id = 1");
            $adim = 'silme';
            $pdo->exec("DELETE FROM `$a` WHERE id = 1");
            $adim = 'tablo kaldırma';
            $pdo->exec("DROP TABLE `$b`, `$a`");
        } catch (Throwable $e) {
            foreach (self::SINAMA_TABLOLARI as $t) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `$t`");
                } catch (Throwable) {
                }
            }

            return $this->madde('Yetki', false, 'Göçlerin ihtiyacı olan "'.$adim.'" yapılamadı: '.self::temizMesaj($e));
        }

        return $this->madde('Yetki', true, 'Tablo, dizin, yabancı anahtar, değişiklik, yazma ve silme denendi; sınama tabloları kaldırıldı.');
    }

    /** @return array{ad: string, tamam: bool, ayrinti: string} */
    private function madde(string $ad, bool $tamam, string $ayrinti): array
    {
        return ['ad' => $ad, 'tamam' => $tamam, 'ayrinti' => $ayrinti];
    }

    /** PDO iletisinin başındaki "SQLSTATE[...]" gürültüsü atılır; parola iletide geçmez. */
    private static function temizMesaj(Throwable $e): string
    {
        return mb_substr(trim((string) preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[\d+\]\s*)?/', '', $e->getMessage())), 0, 300);
    }
}
