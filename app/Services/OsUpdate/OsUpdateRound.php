<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use App\Services\Rotation\BulkRound;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Toplu kurulumun ve toplu denetimin TEK göstergesi.
 *
 * Parola döndürmedeki göstergenin (BulkRound) aynısı: aynı parça, aynı yanıt
 * biçimi, aynı betik (bulk-progress.js). Sayılar makine durumlarından
 * okunur; gösterge tahmin etmez. Kurulum ve denetim ayrı turlardır ve
 * kartları aynı anda durabilir: denetim başlatmak süren kurulumun kartını
 * silmez.
 */
class OsUpdateRound
{
    public const KURULUM = 'kurulum';

    public const DENETIM = 'denetim';

    /** Panelden yeniden başlatma: komut, makinenin kapanıp açılması ve ardından denetim (bkz. OsRebooter). */
    public const YENIDEN = 'yeniden';

    private const PARTI = 'os-update:round:';

    private const SON = 'os-update:round-last:';

    private const OMUR = 21600;

    private const BASLIKLAR = [
        self::KURULUM => 'Toplu sistem güncellemesi',
        self::DENETIM => 'Güncelleme denetimi',
        self::YENIDEN => 'Yeniden başlatma',
    ];

    /**
     * Turu başlatır; aynı türden SÜREN bir tur varsa yenisini açmaz, kayıtları
     * ona ekler. Süren denetimin üstüne elle bir denetim daha başlatılınca
     * eski kart kayboluyor, yerine tek makinelik yeni kart açılıyordu.
     *
     * Her kaydın kendi başlangıç anı tutulur: tura sonradan eklenen (ya da
     * yeniden denetlenen) makine, önceki sonucuyla "bitti" sayılmasın.
     *
     * @param  list<int>  $kimlikler
     * @param  int|null  $baslangic  İşler sıraya alınmadan ÖNCEKİ an: hızlı biten
     *                               bir iş turun başlangıcından önce yazılmış sayılmasın.
     */
    public static function baslat(User $kullanici, array $kimlikler, string $tur = self::KURULUM, ?int $baslangic = null): string
    {
        $baslangic ??= now()->timestamp;
        $kimlikler = array_values(array_unique($kimlikler));

        return Cache::lock(self::PARTI.'kilit:'.$kullanici->getKey(), 10)->block(5, function () use ($kullanici, $kimlikler, $tur, $baslangic): string {
            $parti = self::son($kullanici, $tur);
            $veri = $parti === null ? null : Cache::get(self::PARTI.$parti);

            if (! is_array($veri) || ! (self::durum($kullanici, $parti)['running'] ?? false)) {
                $parti = (string) Str::uuid();
                $veri = ['kullanici' => $kullanici->getKey(), 'tur' => $tur, 'kayitlar' => [], 'baslangic' => $baslangic, 'baslangiclar' => []];
            }

            $veri['baslangiclar'] ??= array_fill_keys($veri['kayitlar'], $veri['baslangic']);

            foreach ($kimlikler as $id) {
                $veri['baslangiclar'][$id] = $baslangic;
            }

            $veri['kayitlar'] = array_values(array_unique([...$veri['kayitlar'], ...$kimlikler]));

            Cache::put(self::PARTI.$parti, $veri, self::OMUR);
            Cache::put(self::sonAnahtari($kullanici, $tur), $parti, self::OMUR);

            return $parti;
        });
    }

    public static function son(User $kullanici, string $tur = self::KURULUM): ?string
    {
        $parti = Cache::get(self::sonAnahtari($kullanici, $tur));

        return is_string($parti) && Cache::has(self::PARTI.$parti) ? $parti : null;
    }

    public static function kapat(User $kullanici, string $parti): void
    {
        foreach (array_keys(self::BASLIKLAR) as $tur) {
            if (Cache::get(self::sonAnahtari($kullanici, $tur)) === $parti) {
                Cache::forget(self::sonAnahtari($kullanici, $tur));
            }
        }
    }

    private static function sonAnahtari(User $kullanici, string $tur): string
    {
        return self::SON.$tur.':'.$kullanici->getKey();
    }

    /** @return array<string, mixed>|null */
    public static function durum(User $kullanici, string $parti): ?array
    {
        $veri = Cache::get(self::PARTI.$parti);

        if (! is_array($veri) || $veri['kullanici'] !== $kullanici->getKey()) {
            return null;
        }

        $tur = $veri['tur'] ?? self::KURULUM;
        $adlar = Credential::query()->with('altGrup')->whereIn('id', $veri['kayitlar'])->get()
            ->mapWithKeys(fn (Credential $k): array => [$k->getKey() => OsUpdateTargets::ad($k)]);
        /*
         * Tur sürerken silinen kayıt turdan düşer: sonucu hiç gelmeyeceği için
         * gösterge zaman aşımına dek "sırada" bekliyordu (18.11.2026, silinen
         * iki test sunucusu, 18/20'de kaldı).
         */
        $kimlikler = array_values(array_filter($veri['kayitlar'], fn (int $id): bool => $adlar->has($id)));
        $durumlar = OsUpdateState::query()->whereIn('credential_id', $kimlikler)->get()->keyBy('credential_id');

        /* Kuyruk durmuşsa sonsuza dek "sürüyor" denmesin. */
        $isSuresi = match ($tur) {
            self::DENETIM => (int) config('os_update.check_timeout', 180),
            /* Komut + kapanıp açılma penceresi (bkz. OsRebooter::PENCERE). */
            self::YENIDEN => 660,
            default => (int) config('os_update.apply_timeout', 840),
        };
        /* Süre en son eklenen kayıttan sayılır: sonradan eklenen makine hemen "başlamadı" olmasın. */
        $baslangiclar = $veri['baslangiclar'] ?? [];
        $sonEkleme = max([$veri['baslangic'], ...array_values($baslangiclar)]);
        $zamanAsimi = now()->timestamp - $sonEkleme > count($kimlikler) * ($isSuresi + 120);

        $sayilar = ['success' => 0, 'failed' => 0, 'unverified' => 0, 'unstarted' => 0, 'pending' => 0];
        $suren = null;
        $sorunlu = [];
        $kayitlar = [];

        foreach ($kimlikler as $id) {
            $ad = (string) $adlar[$id];
            $durum = $durumlar->get($id);
            $kayitBaslangici = (int) ($baslangiclar[$id] ?? $veri['baslangic']);
            $anahtar = match ($tur) {
                self::DENETIM => self::denetimAnahtari($durum, $kayitBaslangici, $zamanAsimi),
                self::YENIDEN => self::yenidenAnahtari(OsRebooter::sonuc($id), $kayitBaslangici, $zamanAsimi),
                default => self::anahtar($durum, $kayitBaslangici, $zamanAsimi),
            };

            match ($anahtar) {
                'success' => $sayilar['success']++,
                'failed' => $sayilar['failed']++,
                'unstarted' => $sayilar['unstarted']++,
                'running' => $suren ??= $ad,
                default => $sayilar['pending']++,
            };

            if (in_array($anahtar, ['failed', 'unstarted'], true)) {
                $hata = match ($tur) {
                    self::DENETIM => $durum?->check_error,
                    self::YENIDEN => OsRebooter::sonuc($id)['hata'] ?? null,
                    default => $durum?->apply_error,
                };
                $sorunlu[] = ['name' => $ad, 'status' => (string) ($hata ?? 'Yanıt yok')];
            }

            $kayitlar[] = ['name' => $ad, 'status' => $anahtar];
        }

        $toplam = count($kimlikler);
        $biten = $sayilar['success'] + $sayilar['failed'] + $sayilar['unstarted'];
        $calisiyor = $biten < $toplam;
        $sorun = $sayilar['failed'] + $sayilar['unstarted'];

        return [
            'running' => $calisiyor,
            'total' => $toplam,
            'done' => $biten,
            'percent' => $toplam === 0 ? 100 : (int) floor($biten / $toplam * 100),
            ...$sayilar,
            'current' => $calisiyor ? $suren : null,
            'text' => match (true) {
                $calisiyor && $suren !== null && $tur === self::YENIDEN => "Yeniden başlatılıyor: {$suren}",
                $calisiyor && $suren !== null => "Kuruluyor: {$suren}",
                $calisiyor && $tur === self::DENETIM => 'Denetleniyor…',
                $calisiyor => 'Sıradaki makine bekleniyor…',
                $sorun === 0 && $tur === self::DENETIM => 'Tamamlandı, hepsi denetlendi.',
                $sorun === 0 && $tur === self::YENIDEN => 'Tamamlandı, hepsi yeniden başladı.',
                $sorun === 0 => 'Tamamlandı, hepsi başarılı.',
                default => "Tamamlandı, {$sorun} makinede sorun var.",
            },
            'problems' => $sorunlu,
            'records' => $kayitlar,
        ];
    }

    /**
     * Ekrandaki kartlar: önce kurulum, sonra denetim, sonra yeniden başlatma; olmayan atlanır.
     *
     * @return list<array<string, mixed>>
     */
    public static function kartlar(User $kullanici): array
    {
        return array_values(array_filter([
            self::kart($kullanici, self::KURULUM),
            self::kart($kullanici, self::DENETIM),
            self::kart($kullanici, self::YENIDEN),
        ]));
    }

    /**
     * Gösterge kartının hazır verisi (credentials/partials/bulk-progress ile aynı biçim).
     *
     * @return array<string, mixed>|null
     */
    public static function kart(User $kullanici, string $tur = self::KURULUM): ?array
    {
        $parti = self::son($kullanici, $tur);
        $durum = $parti === null ? null : self::durum($kullanici, $parti);

        if ($durum === null) {
            return null;
        }

        $sorunSayisi = $durum['failed'] + $durum['unstarted'];
        $lejant = [];

        foreach (BulkRound::ETIKETLER as $anahtar => $etiket) {
            $sayi = collect($durum['records'])->where('status', $anahtar)->count();

            if ($sayi > 0) {
                $lejant[] = ['key' => $anahtar, 'label' => $etiket, 'count' => $sayi];
            }
        }

        return [
            'round' => $parti,
            'title' => self::BASLIKLAR[$tur],
            'progressUrl' => route('os-updates.round.status', $parti),
            'dismissUrl' => route('os-updates.round.dismiss', $parti),
            'status' => $durum,
            'problemCount' => $sorunSayisi,
            'result' => $durum['running'] ? 'running' : ($sorunSayisi === 0 ? 'success' : 'problem'),
            'labels' => BulkRound::ETIKETLER,
            'legend' => $lejant,
        ];
    }

    private static function anahtar(?OsUpdateState $durum, int $baslangic, bool $zamanAsimi): string
    {
        /* Bu partiden önceki bir kurulumun sonucu bu partinin sonucu değildir. */
        if ($durum === null || $durum->apply_queued_at === null || $durum->apply_queued_at->timestamp < $baslangic) {
            return $zamanAsimi ? 'unstarted' : 'waiting';
        }

        return match ($durum->apply_status) {
            OsUpdateState::BASARILI => 'success',
            OsUpdateState::BASARISIZ, OsUpdateState::ATLANDI => 'failed',
            OsUpdateState::SURUYOR => $zamanAsimi ? 'unstarted' : 'running',
            default => $zamanAsimi ? 'unstarted' : 'waiting',
        };
    }

    /**
     * Yeniden başlatmanın durumu: OsRebooter'ın makine başına yazdığı son
     * sonuç. Bu turdan önceki bir yeniden başlatmanın sonucu sayılmaz.
     *
     * @param  array{durum: string, zaman: int, hata: string|null}|null  $sonuc
     */
    private static function yenidenAnahtari(?array $sonuc, int $baslangic, bool $zamanAsimi): string
    {
        if ($sonuc === null || $sonuc['zaman'] < $baslangic) {
            return $zamanAsimi ? 'unstarted' : 'waiting';
        }

        return match ($sonuc['durum']) {
            OsRebooter::BASARILI => 'success',
            OsRebooter::BASARISIZ => 'failed',
            OsRebooter::SURUYOR => $zamanAsimi ? 'unstarted' : 'running',
            default => $zamanAsimi ? 'unstarted' : 'waiting',
        };
    }

    /**
     * Denetimin "bitti" işareti: makinenin denetim zamanı bu turdan sonra.
     * Denetimin "sürüyor" aşaması kaydedilmiyor; iş ya sırada ya bitmiş.
     */
    private static function denetimAnahtari(?OsUpdateState $durum, int $baslangic, bool $zamanAsimi): string
    {
        if ($durum?->checked_at === null || $durum->checked_at->timestamp < $baslangic) {
            return $zamanAsimi ? 'unstarted' : 'waiting';
        }

        return $durum->check_error === null ? 'success' : 'failed';
    }
}
