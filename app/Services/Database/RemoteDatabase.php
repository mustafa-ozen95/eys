<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Panelin veritabanı: şu an nerede, ve uzak bir hedef taşımaya uygun mu?
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
     * Taşımadan önceki sınamalar. Sıra önemli: bağlanamayan hedefte
     * sonrakiler denenmez.
     *
     * @param  array{host: string, port: int, database: string, username: string, password: string}  $hedef
     * @return list<array{ad: string, tamam: bool, ayrinti: string}>
     */
    public function sina(array $hedef): array
    {
        $sonuc = [];
        $kaynak = $this->bilgi();

        $adres = $kaynak['host'].':'.$kaynak['port'].'/'.$kaynak['database'];
        $sonuc[] = $this->madde(
            'Panel kurulumun veritabanında',
            $kaynak['kaynak'] === 'ortam',
            $kaynak['kaynak'] === 'ortam'
                ? 'Kopya şu anki veritabanından ('.$adres.') alınacak.'
                : 'Panel zaten bu sekmeden taşınmış bir veritabanında ('.$adres.'); ikinci kez taşınmaz.',
        );

        $ayni = strtolower($hedef['host']) === strtolower($kaynak['host'])
            && (string) $hedef['port'] === $kaynak['port'] && $hedef['database'] === $kaynak['database'];
        $sonuc[] = $this->madde(
            'Hedef başka bir veritabanı',
            ! $ayni,
            $ayni ? 'Hedef, panelin şu anki veritabanının kendisi.' : 'Şu anki veritabanından farklı.',
        );

        $sonuc[] = $this->madde(
            'Şu anki veritabanında yalnızca tablo var',
            $kaynak['ozel'] === 0,
            match ($kaynak['ozel']) {
                0 => 'Görünüm, yordam, tetikleyici ya da zamanlanmış olay yok.',
                null => 'Şu anki veritabanı okunamadı.',
                default => $kaynak['ozel'].' görünüm/yordam/tetikleyici/olay var; taşıma bunları kopyalamaz.',
            },
        );

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
