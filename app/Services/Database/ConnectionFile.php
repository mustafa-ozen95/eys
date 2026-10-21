<?php

declare(strict_types=1);

namespace App\Services\Database;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

/**
 * Panelin kendi veritabanı ayarı: uzak bağlantı ve taşımanın durumu.
 *
 * İKİ DOSYA, depolama hacminde (storage/app/database):
 *
 *   connection.json  APP_KEY ile ŞİFRELİ: konak, port, veritabanı, kullanıcı, parola
 *   state.json     düz: taşımanın aşaması, zamanı, sebebi; sır içermez
 *
 * Bağlantıyı panel YAZAR, başlatıcı (dc-database) OKUR. Laravel ayarları
 * ortamdan okuduğu için bağlantı açılışta ortama geçer ve conf'taki ya da
 * dağıtım aracındaki DB_* değerlerini ezer; çalışan bir panelin bağlantısı
 * yeniden başlatmadan değişmez.
 *
 * AŞAMALAR (state.json → durum):
 *
 *   tasinacak  panel istedi; başlatıcı bir sonraki açılışta kopyalar
 *   tasiniyor  başlatıcı kopyalıyor
 *   deneniyor  kopya doğrulandı, uzak veritabanıyla ilk açılış sürüyor
 *   etkin      panel uzak veritabanında; GERİ DÜŞÜLMEZ
 *   basarisiz  taşınmadı, kurulumun veritabanıyla devam; bağlantı dosyası silinir
 *
 * "etkin"den sonra uzak veritabanı açılmazsa panel BEKLER, içeridekine
 * dönmez: iç kopya taşıma anında donmuştur ve oraya dönmek, o andan beri
 * yazılan her şeyi sessizce yok saymak olurdu.
 *
 * Başlatıcı bu sınıfı uygulamayı ayağa kaldırmadan kullanır (vendor
 * autoload + Encrypter); bu yüzden Laravel yardımcılarına yalnızca dizin
 * verilmediğinde başvurur.
 */
class ConnectionFile
{
    public const DURUMLAR = ['tasinacak', 'tasiniyor', 'deneniyor', 'etkin', 'basarisiz'];

    /** Bağlantının alanları; başka alan okunmaz, yazılmaz. */
    public const ALANLAR = ['host', 'port', 'database', 'username', 'password'];

    private string $dizin;

    public function __construct(private Encrypter $sifre, string $dizin = '')
    {
        $this->dizin = $dizin !== '' ? rtrim($dizin, '/') : storage_path('app/database');
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}|null
     *
     * @throws RuntimeException dosya var ama çözülemiyorsa (APP_KEY değişmiş olabilir)
     */
    public function baglanti(): ?array
    {
        $yol = $this->dizin.'/connection.json';

        if (! is_file($yol)) {
            return null;
        }

        try {
            $veri = json_decode((string) $this->sifre->decryptString((string) file_get_contents($yol)), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Bağlantı dosyası çözülemedi (APP_KEY değişmiş olabilir): '.$yol);
        }

        return self::temizle((array) $veri);
    }

    /** @param  array<string, mixed>  $baglanti */
    public function baglantiYaz(array $baglanti): void
    {
        $this->yaz('connection.json', $this->sifre->encryptString(json_encode(self::temizle($baglanti), JSON_THROW_ON_ERROR)));
    }

    public function baglantiSil(): void
    {
        @unlink($this->dizin.'/connection.json');
    }

    /** @return array<string, mixed>|null */
    public function durum(): ?array
    {
        $yol = $this->dizin.'/state.json';
        $veri = is_file($yol) ? json_decode((string) file_get_contents($yol), true) : null;

        return is_array($veri) && in_array($veri['durum'] ?? null, self::DURUMLAR, true) ? $veri : null;
    }

    public function asama(): ?string
    {
        return $this->durum()['durum'] ?? null;
    }

    /**
     * Aşamayı ilerletir; isteyen, hedef gibi önceki alanlar korunur. Açıklama
     * verilmezse öncekisi kalır: "etkin" kopyanın özetini taşımaya devam eder.
     *
     * "basarisiz"da bağlantı dosyası SİLİNİR: içinde parola var ve bir daha
     * okunmayacak; kalırsa yalnızca sızabilir.
     *
     * @param  array<string, mixed>  $ek
     */
    public function asamaYaz(string $durum, ?string $aciklama = null, array $ek = []): void
    {
        if (! in_array($durum, self::DURUMLAR, true)) {
            throw new RuntimeException('Bilinmeyen aşama: '.$durum);
        }

        $onceki = $durum === 'tasinacak' ? [] : ($this->durum() ?? []);

        $this->yaz('state.json', json_encode(array_filter([
            ...$onceki,
            ...$ek,
            'durum' => $durum,
            'aciklama' => $aciklama ?? ($onceki['aciklama'] ?? null),
            'zaman' => date('c'),
        ], fn ($v) => $v !== null), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($durum === 'basarisiz') {
            $this->baglantiSil();
        }
    }

    /**
     * Konteynerin yeniden başlatılmasını ister.
     *
     * Panel (uygulama kullanıcısı) süreç yöneticisine erişemez; dosyayı root
     * olarak koşan dc-restart görür ve yalnızca bu tek işi yapar.
     */
    public function yenidenBaslatIste(): bool
    {
        return $this->yaz('restart', date('c'), false);
    }

    /**
     * Başlatıcının ortama alacağı satırlar: AD=base64(değer).
     *
     * Değer base64'tür: parolada boşluk, tırnak ya da satır sonu olabilir ve
     * kabuk onu ne eval ile ne de düz okuma ile güvenle taşır.
     */
    public function ortamSatirlari(): string
    {
        $b = $this->baglanti() ?? throw new RuntimeException('Bağlantı dosyası yok.');

        return implode("\n", [
            'DB_HOST='.base64_encode($b['host']),
            'DB_PORT='.base64_encode((string) $b['port']),
            'DB_DATABASE='.base64_encode($b['database']),
            'DB_USERNAME='.base64_encode($b['username']),
            'DB_PASSWORD='.base64_encode($b['password']),
        ])."\n";
    }

    /**
     * mariadb istemcisinin seçenek dosyası (--defaults-extra-file).
     *
     * Parola komut satırına GİRMEZ: orada süreç listesinden okunur.
     */
    public function istemciAyari(): string
    {
        return self::istemciMetni($this->baglanti() ?? throw new RuntimeException('Bağlantı dosyası yok.'));
    }

    /**
     * mariadb istemci seçenek dosyası: parola komut satırına (ve süreç
     * listesine) hiç girmez. Başlatıcı ortamın verdiği bağlantı için de
     * bunu kullanır (dc-database ortam-istemci).
     *
     * @param  array{host: string, port: int, username: string, password: string}  $b
     */
    public static function istemciMetni(array $b): string
    {
        $tirnakla = fn (string $s): string => '"'.addcslashes($s, "\\\"\n\r\t").'"';

        return "[client]\n"
            .'host='.$tirnakla($b['host'])."\n"
            .'port='.$b['port']."\n"
            .'user='.$tirnakla($b['username'])."\n"
            .'password='.$tirnakla($b['password'])."\n";
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public static function temizle(array $b): array
    {
        return [
            'host' => trim((string) ($b['host'] ?? '')),
            'port' => (int) ($b['port'] ?? 3306) ?: 3306,
            'database' => trim((string) ($b['database'] ?? '')),
            'username' => trim((string) ($b['username'] ?? '')),
            'password' => (string) ($b['password'] ?? ''),
        ];
    }

    /** Geçici dosyaya yazıp yerine taşır: yarım yazılmış bir dosya okunmasın. */
    private function yaz(string $ad, string $icerik, bool $firlat = true): bool
    {
        if (! is_dir($this->dizin) && ! @mkdir($this->dizin, 0700, true) && ! is_dir($this->dizin)) {
            return $firlat ? throw new RuntimeException('Dizin açılamadı: '.$this->dizin) : false;
        }

        $gecici = $this->dizin.'/.'.$ad.'.'.bin2hex(random_bytes(4));
        $eski = umask(0077);

        try {
            $tamam = @file_put_contents($gecici, $icerik) !== false && @rename($gecici, $this->dizin.'/'.$ad);
        } finally {
            umask($eski);
        }

        if (! $tamam) {
            @unlink($gecici);

            return $firlat ? throw new RuntimeException('Yazılamadı: '.$this->dizin.'/'.$ad) : false;
        }

        return true;
    }
}
