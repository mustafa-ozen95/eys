<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Panelin yeni sürümü var mı?
 *
 * Çalışan sürüm (imaja derlemede yazılan commit, UYGULAMA_SURUMU) GitHub'daki
 * main dalının SON BAŞARILI İMAJ DERLEMESİYLE karşılaştırılır. Son commit
 * değil: imajı henüz derlenmemiş ya da derlemesi düşmüş bir commit
 * kurulamaz, onu "yeni sürüm" diye göstermek yanıltırdı.
 *
 * YÖN ÖLÇÜLÜR. "Farklı" demek yetmiyor: çalışan sürüm kayıttakinden YENİ de
 * olabilir (yeni commit'in derlemesi düşmüş, elle kurulmuş bir imaj). O
 * zaman "güncelleme" denen şey geri almak olurdu. Karşılaştırma GitHub'ın
 * compare'iyle yapılır ve kaç commit geride, kaç commit ileride olduğu ayrı
 * ayrı söylenir.
 *
 * JETON İSTEĞE BAĞLI. Depo açıkken jetonsuz sorulur (GitHub'ın jetonsuz
 * sınırı IP başına saatte 60 istek; günde bir tur ve elle basılan düğme
 * için yeter). Özel depo jeton ister; jetonsuz sorulan özel depo 404 döner.
 *
 * YALNIZCA BAKAR, KURMAZ. Güncelleme sunucudan, onaylı yapılır; panel
 * kendini güncelleyebilseydi, ele geçen bir panel sunucuyu yönetebilirdi.
 *
 * Sonuç depolama hacminde durur (storage/app/update/state.json): günlük
 * tur yazar, ekran ve uyarı şeridi okur. Jeton sonuçta ve iletilerde geçmez.
 *
 * Panele özgü olan yalnızca config/update.php'deki depo adıdır.
 */
class UpdateCheck
{
    public const DURUMLAR = ['guncel', 'yeni', 'ileride', 'ayrisik', 'bilinmiyor', 'hata'];

    /** Ekranda gösterilen yeni commit sayısı; fazlası "ve N commit daha" olur. */
    private const EN_COK_COMMIT = 20;

    private const API = 'https://api.github.com';

    private string $dizin;

    public function __construct(string $dizin = '')
    {
        $this->dizin = $dizin !== '' ? rtrim($dizin, '/') : storage_path('app/update');
    }

    /** Çalışan commit (40 haneli); imaj sürüm bilgisi olmadan derlendiyse null. */
    public function surum(): ?string
    {
        $surum = strtolower(trim((string) config('update.surum')));

        return preg_match('/^[0-9a-f]{40}$/', $surum) === 1 ? $surum : null;
    }

    public function depo(): string
    {
        return (string) config('update.depo');
    }

    /**
     * GitHub'a sorar, sonucu yazar ve döndürür. Hata FIRLATMAZ: sonuç
     * "hata" durumuyla yazılır; günlük tur da düğme de aynı yoldan geçer.
     *
     * @return array<string, mixed>
     */
    public function denetle(string $tetikleyen): array
    {
        $sonuc = ['calisan' => $this->surum(), 'depo' => $this->depo(), 'tetikleyen' => $tetikleyen];

        try {
            $sonuc = [...$sonuc, ...$this->sor($sonuc['calisan'])];
        } catch (Throwable $e) {
            $sonuc = [...$sonuc, 'durum' => 'hata', 'aciklama' => $this->temiz($e->getMessage())];
        }

        $sonuc['zaman'] = date('c');
        $this->yaz($sonuc);

        return $sonuc;
    }

    /** @return array<string, mixed>|null */
    public function son(): ?array
    {
        $yol = $this->dizin.'/state.json';
        $veri = is_file($yol) ? json_decode((string) file_get_contents($yol), true) : null;

        return is_array($veri) && in_array($veri['durum'] ?? null, self::DURUMLAR, true) ? $veri : null;
    }

    /**
     * Uyarı şeridi için: son kontrol yeni sürüm bulduysa VE o kontrol şu an
     * çalışan sürümle yapıldıysa. Panel güncellendikten sonra eski sonuç
     * yeniden kontrol beklemeden susar.
     */
    public function yeniVar(): bool
    {
        $son = $this->son();

        return ($son['durum'] ?? null) === 'yeni' && ($son['calisan'] ?? null) === $this->surum();
    }

    /** @return array<string, mixed> */
    private function sor(?string $calisan): array
    {
        $jeton = trim((string) config('update.jeton'));

        $http = Http::baseUrl(self::API)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(10);

        if ($jeton !== '') {
            $http = $http->withToken($jeton);
        }

        $is = $this->al($http, '/repos/'.$this->depo().'/actions/workflows/'.config('update.is', 'image.yml').'/runs', [
            'branch' => config('update.dal', 'main'),
            'status' => 'success',
            'event' => 'push',
            'per_page' => 1,
        ])['workflow_runs'][0] ?? null;

        if (! is_array($is) || ! is_string($is['head_sha'] ?? null)) {
            return ['durum' => 'hata', 'aciklama' => 'Depoda başarılı bir imaj derlemesi bulunamadı.'];
        }

        $son = ['son' => $is['head_sha'], 'son_tarih' => $is['updated_at'] ?? null];

        if ($calisan === null) {
            return [...$son, 'durum' => 'bilinmiyor', 'aciklama' => 'Çalışan sürüm bilinmiyor: imaj sürüm bilgisi olmadan derlenmiş. Son derleme gösteriliyor.'];
        }

        if ($calisan === $is['head_sha']) {
            return [...$son, 'durum' => 'guncel', 'geride' => 0, 'ileride' => 0, 'aciklama' => 'Panel son sürümde.'];
        }

        $fark = $this->al($http, '/repos/'.$this->depo().'/compare/'.$calisan.'...'.$is['head_sha']);
        $geride = (int) ($fark['ahead_by'] ?? 0);
        $ileride = (int) ($fark['behind_by'] ?? 0);

        $commitler = collect($fark['commits'] ?? [])
            ->reverse()
            ->take(self::EN_COK_COMMIT)
            ->map(fn (array $c): array => [
                'sha' => (string) ($c['sha'] ?? ''),
                'baslik' => mb_substr(strtok((string) ($c['commit']['message'] ?? ''), "\n") ?: '', 0, 200),
                'tarih' => $c['commit']['committer']['date'] ?? null,
            ])
            ->values()
            ->all();

        return [...$son, 'geride' => $geride, 'ileride' => $ileride, 'commitler' => $commitler, ...match ($fark['status'] ?? null) {
            'ahead' => ['durum' => 'yeni', 'aciklama' => $geride.' commit geride.'],
            'identical' => ['durum' => 'guncel', 'aciklama' => 'Panel son sürümde.'],
            'behind' => ['durum' => 'ileride', 'aciklama' => 'Çalışan sürüm son başarılı derlemeden '.$ileride.' commit ileride: daha yeni bir commit\'ten kurulmuş. "Güncellemek" geri almak olur.'],
            default => ['durum' => 'ayrisik', 'aciklama' => 'Çalışan sürüm main dalında değil ('.$geride.' geride, '.$ileride.' ileride): ayrı bir daldan ya da elle derlenmiş.'],
        }];
    }

    /**
     * @param  array<string, mixed>  $sorgu
     * @return array<string, mixed>
     */
    private function al(PendingRequest $http, string $yol, array $sorgu = []): array
    {
        try {
            $yanit = $http->get($yol, $sorgu);
        } catch (ConnectionException) {
            throw new RuntimeException('GitHub\'a erişilemedi (ağ kapalı ya da yanıt yok).');
        }

        $jetonsuz = trim((string) config('update.jeton')) === '';

        return match (true) {
            $yanit->successful() => (array) $yanit->json(),
            $yanit->status() === 401 => throw new RuntimeException('GitHub jetonu reddetti (401): süresi dolmuş ya da silinmiş olabilir.'),
            $jetonsuz && $yanit->status() === 403 && $yanit->header('X-RateLimit-Remaining') === '0' => throw new RuntimeException('GitHub jetonsuz istek sınırı doldu (403, saatte 60): bir saat sonra yeniden deneyin ya da GUNCELLEME_JETONU tanımlayın.'),
            $jetonsuz && in_array($yanit->status(), [403, 404], true) => throw new RuntimeException('Depo bulunamadı ya da özel ('.$yanit->status().'): '.$this->depo().' özelse GUNCELLEME_JETONU gerekir.'),
            in_array($yanit->status(), [403, 404], true) => throw new RuntimeException('Jeton bu depoyu görmüyor ('.$yanit->status().'): '.$this->depo().' için Contents ve Actions okuma yetkisi gerekir.'),
            default => throw new RuntimeException('GitHub yanıtı: '.$yanit->status().' '.$this->mesaj($yanit)),
        };
    }

    private function mesaj(Response $yanit): string
    {
        return mb_substr((string) ($yanit->json('message') ?? ''), 0, 200);
    }

    /** Jeton hiçbir iletide geçmez. */
    private function temiz(string $ileti): string
    {
        $jeton = trim((string) config('update.jeton'));

        return mb_substr($jeton !== '' ? str_replace($jeton, '***', $ileti) : $ileti, 0, 300);
    }

    /** @param  array<string, mixed>  $sonuc */
    private function yaz(array $sonuc): void
    {
        if (! is_dir($this->dizin) && ! @mkdir($this->dizin, 0700, true) && ! is_dir($this->dizin)) {
            throw new RuntimeException('Dizin açılamadı: '.$this->dizin);
        }

        $gecici = $this->dizin.'/.state.json.'.bin2hex(random_bytes(4));

        if (@file_put_contents($gecici, json_encode($sonuc, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false
            || ! @rename($gecici, $this->dizin.'/state.json')) {
            @unlink($gecici);
            throw new RuntimeException('Yazılamadı: '.$this->dizin.'/state.json');
        }
    }
}
