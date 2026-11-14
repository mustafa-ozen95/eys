<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Jobs\ApplyOsUpdatesJob;
use App\Jobs\CheckOsUpdatesJob;
use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Seçilen kayıtları sıraya alır.
 *
 * SEÇMEK YETKİ DEĞİLDİR: her kayıt ayrı ayrı yetkiye, hedef kuralına ve
 * kilide sorulur. Ekrandaki kapalı onay kutusu kolaylıktır; karar burada.
 */
class OsUpdateLauncher
{
    public function __construct(
        private OsUpdateTargets $targets,
        private OsUpdater $updater,
    ) {}

    /**
     * @param  Collection<int, Credential>  $kayitlar
     * @return array{queued: list<int>, skipped: array<string, string>}
     */
    public function kur(Collection $kayitlar, string $mod, User $user, string $reason): array
    {
        $sonuc = ['queued' => [], 'skipped' => []];
        $gorulen = [];

        foreach ($kayitlar as $kayit) {
            $sebep = $this->engel($kayit, $user, $gorulen);

            if ($sebep !== null) {
                $sonuc['skipped'][$kayit->name] = $sebep;

                continue;
            }

            $gorulen[OsUpdateTargets::makine($kayit)] = true;
            $this->updater->durum($kayit, OsUpdateState::SIRADA, null, $mod, $user);
            ApplyOsUpdatesJob::dispatch($kayit, $mod, $user, $reason);
            $sonuc['queued'][] = $kayit->getKey();
        }

        return $sonuc;
    }

    /**
     * Denetimi sıraya alır (yalnızca okur; kilit denetime engel değildir).
     *
     * @param  Collection<int, Credential>  $kayitlar
     */
    public function denetle(Collection $kayitlar, User $user): int
    {
        $gorulen = [];

        foreach ($kayitlar as $kayit) {
            $makine = OsUpdateTargets::makine($kayit);

            if (isset($gorulen[$makine]) || $user->cannot('osUpdate', $kayit) || ! $this->targets->hedefMi($kayit)) {
                continue;
            }

            $gorulen[$makine] = true;
            CheckOsUpdatesJob::dispatch($kayit);
        }

        return count($gorulen);
    }

    /** @param  array<string, true>  $gorulen */
    private function engel(Credential $kayit, User $user, array $gorulen): ?string
    {
        if ($user->cannot('osUpdate', $kayit)) {
            return 'yetki yok';
        }

        if (! $this->targets->hedefMi($kayit)) {
            return 'SSH bilgisi olan etkin bir Linux kaydı değil';
        }

        if (isset($gorulen[OsUpdateTargets::makine($kayit)])) {
            return 'aynı makine zaten sırada';
        }

        if ($this->targets->kilit($kayit) !== null) {
            return 'kilitli';
        }

        if ($kayit->osUpdateState?->isBusy()) {
            return 'kurulum zaten sürüyor';
        }

        return null;
    }

    /** @param  array<string, string>  $atlananlar */
    public static function ozet(array $atlananlar): string
    {
        return collect($atlananlar)
            ->map(fn (string $sebep, string $ad): string => "{$ad} ({$sebep})")
            ->take(8)
            ->implode(', ').(count($atlananlar) > 8 ? ' …' : '');
    }
}
