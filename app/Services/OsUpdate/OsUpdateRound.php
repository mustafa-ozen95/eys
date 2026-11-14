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
 * Toplu kurulumun TEK göstergesi.
 *
 * Parola döndürmedeki göstergenin (BulkRound) aynısı: aynı parça, aynı yanıt
 * biçimi, aynı betik (bulk-progress.js). Sayılar kurulum durumlarından
 * okunur; gösterge tahmin etmez.
 */
class OsUpdateRound
{
    private const PARTI = 'os-update:round:';

    private const SON = 'os-update:round-last:';

    private const OMUR = 21600;

    public static function baslat(User $kullanici, array $kimlikler): string
    {
        $parti = (string) Str::uuid();

        Cache::put(self::PARTI.$parti, [
            'kullanici' => $kullanici->getKey(),
            'kayitlar' => array_values(array_unique($kimlikler)),
            'baslangic' => now()->timestamp,
        ], self::OMUR);

        Cache::put(self::SON.$kullanici->getKey(), $parti, self::OMUR);

        return $parti;
    }

    public static function son(User $kullanici): ?string
    {
        $parti = Cache::get(self::SON.$kullanici->getKey());

        return is_string($parti) && Cache::has(self::PARTI.$parti) ? $parti : null;
    }

    public static function kapat(User $kullanici, string $parti): void
    {
        if (Cache::get(self::SON.$kullanici->getKey()) === $parti) {
            Cache::forget(self::SON.$kullanici->getKey());
        }
    }

    /** @return array<string, mixed>|null */
    public static function durum(User $kullanici, string $parti): ?array
    {
        $veri = Cache::get(self::PARTI.$parti);

        if (! is_array($veri) || $veri['kullanici'] !== $kullanici->getKey()) {
            return null;
        }

        $kimlikler = $veri['kayitlar'];
        $durumlar = OsUpdateState::query()->whereIn('credential_id', $kimlikler)->get()->keyBy('credential_id');
        $adlar = Credential::query()->whereIn('id', $kimlikler)->pluck('name', 'id');

        /* Kuyruk durmuşsa sonsuza dek "sürüyor" denmesin. */
        $zamanAsimi = now()->timestamp - $veri['baslangic']
            > count($kimlikler) * ((int) config('os_update.apply_timeout', 840) + 120);

        $sayilar = ['success' => 0, 'failed' => 0, 'unverified' => 0, 'unstarted' => 0, 'pending' => 0];
        $suren = null;
        $sorunlu = [];
        $kayitlar = [];

        foreach ($kimlikler as $id) {
            $ad = (string) ($adlar[$id] ?? "#{$id}");
            $durum = $durumlar->get($id);
            $anahtar = self::anahtar($durum, $veri['baslangic'], $zamanAsimi);

            match ($anahtar) {
                'success' => $sayilar['success']++,
                'failed' => $sayilar['failed']++,
                'unstarted' => $sayilar['unstarted']++,
                'running' => $suren ??= $ad,
                default => $sayilar['pending']++,
            };

            if (in_array($anahtar, ['failed', 'unstarted'], true)) {
                $sorunlu[] = ['name' => $ad, 'status' => (string) ($durum?->apply_error ?? 'Yanıt yok')];
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
                $calisiyor && $suren !== null => "Kuruluyor: {$suren}",
                $calisiyor => 'Sıradaki makine bekleniyor…',
                $sorun === 0 => 'Tamamlandı, hepsi başarılı.',
                default => "Tamamlandı, {$sorun} makinede sorun var.",
            },
            'problems' => $sorunlu,
            'records' => $kayitlar,
        ];
    }

    /**
     * Gösterge kartının hazır verisi (credentials/partials/bulk-progress ile aynı biçim).
     *
     * @return array<string, mixed>|null
     */
    public static function kart(User $kullanici): ?array
    {
        $parti = self::son($kullanici);
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
            'title' => 'Toplu sistem güncellemesi',
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
}
