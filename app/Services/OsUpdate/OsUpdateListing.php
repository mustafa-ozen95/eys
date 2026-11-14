<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Models\Credential;
use App\Models\OsUpdateState;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Sistem güncellemeleri" ekranının hazır verisi. Blade yalnızca çizer.
 */
class OsUpdateListing
{
    public const DURUMLAR = [
        '' => 'Hepsi',
        'kritik' => 'Kritik bekleyen',
        'bekleyen' => 'Güncelleme bekleyen',
        'guncel' => 'Güncel',
        'kilitli' => 'Kilitli',
        'sorunlu' => 'Sorunlu',
        'denetlenmedi' => 'Denetlenmedi',
    ];

    public function __construct(private OsUpdateTargets $targets) {}

    /** @return array<string, mixed> */
    public function build(User $user, ?string $arama, ?string $durum): array
    {
        $kilitler = $this->targets->kilitliMakineler();

        $satirlar = $this->targets->kullaniciIcin($user)
            ->with(['group', 'altGrup', 'osUpdateState'])
            ->when($arama, fn ($q, string $a) => $this->ara($q, $a))
            ->ordered()
            ->get()
            ->map(fn (Credential $kayit): array => $this->satir($kayit, $user, $kilitler));

        $sayilar = [
            'makine' => $satirlar->count(),
            'kritik' => $satirlar->where('critical', '>', 0)->count(),
            'bekleyen' => $satirlar->where('pending', '>', 0)->count(),
            'kilitli' => $satirlar->where('locked', true)->count(),
            'yenidenBaslat' => $satirlar->where('reboot', true)->count(),
        ];

        $durum = array_key_exists((string) $durum, self::DURUMLAR) ? (string) $durum : '';

        return [
            'rows' => $satirlar->filter(fn (array $s): bool => $this->uyar($s, $durum))->values(),
            'figures' => $sayilar,
            'durum' => $durum,
            'durumlar' => self::DURUMLAR,
            'query' => $arama,
            'selectable' => $satirlar->where('selectable', true)->count(),
            'checkable' => $satirlar->where('canApply', true)->count(),
            'checkText' => $this->denetimMetni((int) config('os_update.check_hours', 4)),
            'modes' => [OsUpdateScripts::KRITIK => 'Yalnızca kritik', OsUpdateScripts::TUMU => 'Tüm güncellemeler'],
            'round' => OsUpdateRound::kart($user),
        ];
    }

    /**
     * Ekranda yazan adın her parçasında arar: kayıt, alt grup (makine), grup.
     *
     * @param  Builder<Credential>  $sorgu
     * @return Builder<Credential>
     */
    private function ara(Builder $sorgu, string $arama): Builder
    {
        $kalip = '%'.addcslashes($arama, '%_\\').'%';

        return $sorgu->where(fn (Builder $q) => $q
            ->where('name', 'like', $kalip)
            ->orWhereHas('altGrup', fn (Builder $a) => $a->where('name', 'like', $kalip))
            ->orWhereHas('group', fn (Builder $g) => $g->where('name', 'like', $kalip)));
    }

    /**
     * @param  array<string, OsUpdateState>  $kilitler
     * @return array<string, mixed>
     */
    private function satir(Credential $kayit, User $user, array $kilitler): array
    {
        $durum = $kayit->osUpdateState;
        $kilit = $kilitler[OsUpdateTargets::makine($kayit)] ?? null;
        $denetlendi = $durum?->checked_at !== null && $durum->package_manager !== null;
        $kritik = $denetlendi ? (int) $durum->critical_count : 0;
        $normal = $denetlendi ? (int) $durum->normal_count : 0;
        $kurabilir = $user->can('osUpdate', $kayit);
        $mesgul = $durum?->isBusy() === true;

        return [
            'uuid' => $kayit->uuid,
            'name' => OsUpdateTargets::ad($kayit),
            'group' => $kayit->group?->name,
            'checked' => $denetlendi,
            'checkedAt' => $durum?->checked_at?->translatedFormat('d.m.Y H:i'),
            'checkedAgo' => $durum?->checked_at?->diffForHumans(),
            'checkError' => $durum?->check_error,
            'manager' => $durum?->package_manager,
            'critical' => $kritik,
            'normal' => $normal,
            'pending' => $kritik + $normal,
            'reboot' => (bool) $durum?->reboot_required,
            'packages' => collect($durum?->packages ?? [])->take(150)->all(),
            'packageMore' => max(0, count($durum?->packages ?? []) - 150),
            'locked' => $kilit !== null,
            'lockHere' => $kilit !== null && $kilit->credential_id === $kayit->getKey(),
            'lockFrom' => $kilit?->credential === null ? null : OsUpdateTargets::ad($kilit->credential),
            'lockReason' => $kilit?->lock_reason,
            'lockBy' => $kilit?->locker?->name,
            'lockAt' => $kilit?->locked_at?->translatedFormat('d.m.Y H:i'),
            'apply' => $this->kurulum($durum),
            'busy' => $mesgul,
            'canApply' => $kurabilir,
            'canLock' => $user->can('osUpdateLock', $kayit),
            'selectable' => $kurabilir && $kilit === null && ! $mesgul,
            'blocked' => match (true) {
                ! $kurabilir => 'Bu kayıtta sistem güncellemesi kurma yetkiniz yok.',
                $kilit !== null => 'Kilitli: '.$kilit->lock_reason,
                $mesgul => 'Kurulum sürüyor.',
                default => null,
            },
        ];
    }

    private function denetimMetni(int $saat): string
    {
        return match (true) {
            $saat <= 0 => 'Otomatik denetim kapalı',
            $saat === 24 => 'Otomatik denetim: günde bir',
            default => "Otomatik denetim: {$saat} saatte bir",
        };
    }

    /** @return array{label: string, variant: string, error: ?string, at: ?string}|null */
    private function kurulum(?OsUpdateState $durum): ?array
    {
        if ($durum?->apply_status === null) {
            return null;
        }

        [$etiket, $renk] = match ($durum->apply_status) {
            OsUpdateState::SIRADA => ['Sırada', 'gray'],
            OsUpdateState::SURUYOR => ['Kuruluyor', 'blue'],
            OsUpdateState::BASARILI => ['Kuruldu', 'green'],
            OsUpdateState::ATLANDI => ['Atlandı', 'yellow'],
            default => ['Kurulamadı', 'red'],
        };

        return [
            'label' => $etiket.($durum->apply_mode === OsUpdateScripts::KRITIK ? ' (kritik)' : ''),
            'variant' => $renk,
            'error' => $durum->apply_error,
            'at' => ($durum->apply_finished_at ?? $durum->apply_queued_at)?->translatedFormat('d.m.Y H:i'),
        ];
    }

    /** @param  array<string, mixed>  $s */
    private function uyar(array $s, string $durum): bool
    {
        return match ($durum) {
            'kritik' => $s['critical'] > 0,
            'bekleyen' => $s['pending'] > 0,
            'guncel' => $s['checked'] && $s['pending'] === 0,
            'kilitli' => $s['locked'],
            'sorunlu' => $s['checkError'] !== null || ($s['apply']['variant'] ?? null) === 'red',
            'denetlenmedi' => ! $s['checked'],
            default => true,
        };
    }
}
