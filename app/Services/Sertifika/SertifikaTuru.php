<?php

declare(strict_types=1);

namespace App\Services\Sertifika;

use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Sertifika turunun panel tarafındaki yüzü.
 *
 * Panel ile sunucu arasındaki sözleşme DÖRT KÜÇÜK DOSYADIR; başka bir kanal
 * yok. Panelin yazdığı tek şey istek dosyasıdır ve içinde yalnızca eylemin
 * adı bulunur — sunucuda koşacak komutu panel BELİRLEMEZ.
 *
 *   durum.json     ← sunucu yazar: veren, bitiş, kalan gün
 *   kayitlar.json  ← sunucu yazar: eklenecek TXT kayıtları
 *   islem.json     ← sunucu yazar: son işlemin sonucu
 *   istek.json     → panel yazar: {"eylem":"baslat"|"tamamla"}
 *
 * Dosyalar yoksa ekran "bilinmiyor" der ve düğmeler çalışmaz; kurulum
 * bağlamayı unutmuşsa bunu görmek gerekir (sessizce boş bir ekran değil).
 */
class SertifikaTuru
{
    /** Kurulumun konteynere bağladığı yollar (bkz. kurulumun compose dosyası). */
    private const DURUM = '/etc/dc/sertifika-durum.json';

    private const PANEL = '/etc/dc/sertifika-panel';

    /** @return array<string, mixed>|null */
    public function durum(): ?array
    {
        return $this->oku(self::DURUM);
    }

    /**
     * Eklenecek TXT kayıtları.
     *
     * BOŞ LİSTE ile DOSYA YOK farklıdır: boş liste "doğrulanacak bir şey
     * kalmadı" demektir (sağlayıcı adları zaten doğrulanmış sayıyor ve
     * sertifika doğrudan alınabilir); dosya yoksa daha hiç tur açılmamıştır.
     *
     * @return list<array{ad: string, deger: string}>|null
     */
    public function kayitlar(): ?array
    {
        $veri = $this->oku(self::PANEL.'/kayitlar.json');

        return $veri === null ? null : array_values((array) ($veri['kayitlar'] ?? []));
    }

    /** @return array<string, mixed>|null */
    public function sonIslem(): ?array
    {
        return $this->oku(self::PANEL.'/islem.json');
    }

    /** Sunucu henüz isteği almadıysa ekran "işleniyor" der. */
    public function istekBekliyorMu(): bool
    {
        return is_file(self::PANEL.'/istek.json');
    }

    /**
     * Kayıtlar DNS'te göründü mü?
     *
     * Sorgu PANELDEN yapılır: kullanıcının "ekledim" demesi yetmez, kaydın
     * yayıldığını görmek gerekir. Yayılmamış bir kayıtla tur tamamlatmak,
     * başarısız bir deneme ve baştan başlayan bir tur demektir.
     *
     * @return list<array{ad: string, deger: string, yayildi: bool}>
     */
    public function yayilmaDurumu(): array
    {
        $liste = [];

        foreach ($this->kayitlar() ?? [] as $kayit) {
            $liste[] = [
                'ad' => (string) $kayit['ad'],
                'deger' => (string) $kayit['deger'],
                'yayildi' => $this->txtVarMi((string) $kayit['ad'], (string) $kayit['deger']),
            ];
        }

        return $liste;
    }

    /**
     * İsteği bırakır. Sunucudaki ajan dosyayı görür görmez koşar.
     *
     * @param  'baslat'|'tamamla'  $eylem
     */
    public function istekBirak(string $eylem): bool
    {
        if (! is_dir(self::PANEL) || ! is_writable(self::PANEL)) {
            return false;
        }

        $veri = json_encode([
            'eylem' => $eylem,
            'isteyen' => (string) (Auth::user()?->email ?? 'bilinmiyor'),
            'zaman' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE);

        return file_put_contents(self::PANEL.'/istek.json', $veri.PHP_EOL) !== false;
    }

    /**
     * Adın TXT kayıtları arasında bu değer var mı?
     *
     * `dns_get_record` sistemin çözümleyicisine sorar; konteynerin
     * çözümleyicisi de dışarıyı görüyor. Sorgu başarısızsa "yayılmadı"
     * denir — eksik bilgi "tamam" sayılmamalı.
     */
    private function txtVarMi(string $ad, string $deger): bool
    {
        try {
            $kayitlar = @dns_get_record($ad, DNS_TXT) ?: [];
        } catch (Throwable) {
            return false;
        }

        foreach ($kayitlar as $kayit) {
            foreach ((array) ($kayit['entries'] ?? [$kayit['txt'] ?? '']) as $metin) {
                if (trim((string) $metin) === $deger) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function oku(string $yol): ?array
    {
        if (! is_file($yol) || ! is_readable($yol)) {
            return null;
        }

        $veri = json_decode((string) file_get_contents($yol), true);

        return is_array($veri) ? $veri : null;
    }
}
