<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\OsUpdateState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Sistem güncellemeleri" ekranının hazır verisi. Blade yalnızca çizer.
 */
class OsUpdateListing
{
    /** Sol sütundaki "Hepsi": gruptan bağımsız, bütün makineler. */
    public const ALL_GROUPS = 'hepsi';

    /** Grubu olmayan makinelerin sol sütundaki anahtarı. */
    private const GRUPSUZ = 'grupsuz';

    public function __construct(private OsUpdateTargets $targets, private OsUpdateDetail $detail) {}

    /**
     * Erişim bilgileri ekranının düzeni: solda gruplar (en üstte Hepsi),
     * ortada seçili grubun makineleri, sağda seçili makinenin kartı.
     * Seçim adreste taşınır (?grup=, ?kayit=); yalnızca kullanıcının
     * görebildiği makineler arasından çözülür.
     *
     * @return array<string, mixed>
     */
    public function build(User $user, ?string $grup = null, ?string $kayit = null): array
    {
        $kilitler = $this->targets->kilitliMakineler();

        $kayitlar = $this->targets->kullaniciIcin($user)
            ->with(['group', 'altGrup', 'osUpdateState'])
            ->ordered()
            ->get();

        $satirlar = $kayitlar->map(fn (Credential $kayit): array => $this->satir($kayit, $user, $kilitler));

        /* Sıra Gruplar ekranıyla ortak; sürükleme yalnızca grupları sıralayabilene. */
        $sirala = $user->can('create', CredentialGroup::class);
        $gruplar = $this->bolumler($kayitlar, $satirlar, $sirala);
        $secilenGrup = collect($gruplar)->firstWhere('param', $grup);

        /* Hepsi görünümünde satırlar grup başlıkları altında; grup seçiliyken düz liste. */
        $bolumler = $secilenGrup === null ? $gruplar : [[...$secilenGrup, 'name' => null]];
        $liste = collect($bolumler)->flatMap(fn (array $bolum): array => $bolum['rows']);

        $secilen = filled($kayit) ? $kayitlar->firstWhere('uuid', $kayit) : null;

        return [
            'groups' => $gruplar,
            'allCount' => $satirlar->count(),
            'allGroups' => self::ALL_GROUPS,
            'selectedGroup' => $secilenGrup,
            'groupParam' => $secilenGrup['param'] ?? ($grup === self::ALL_GROUPS ? self::ALL_GROUPS : null),
            'listTitle' => $secilenGrup['name'] ?? 'Hepsi',
            'listSummary' => $this->bolumOzeti($liste),
            'sections' => $bolumler,
            'rows' => $liste,
            'canOrder' => $sirala,
            /* Toplu araçlar ve seçim kutuları: kurma/denetleme yetkisi yoksa hiç çizilmez (yalnızca kilit yetkisi olan). */
            'canAct' => $user->can('osUpdateAny', Credential::class),
            'selectable' => $liste->where('selectable', true)->count(),
            'checkable' => $satirlar->where('canApply', true)->count(),
            'checkText' => $this->denetimMetni((int) config('os_update.check_hours', 4)),
            'modes' => [OsUpdateScripts::KRITIK => 'Yalnızca kritik', OsUpdateScripts::TUMU => 'Tüm güncellemeler'],
            'rounds' => OsUpdateRound::kartlar($user),
            'selected' => $secilen === null ? null : $this->detail->build(
                $user,
                $secilen,
                $satirlar->firstWhere('uuid', $secilen->uuid),
                $kayitlar,
            ),
        ];
    }

    /**
     * Satırlar GRUPLARINA göre: Gruplar ekranındaki sırayla (konum, ad).
     *
     * @param  Collection<int, Credential>  $kayitlar
     * @param  Collection<int, array<string, mixed>>  $satirlar
     * @return list<array<string, mixed>>
     */
    private function bolumler(Collection $kayitlar, Collection $satirlar, bool $sirala): array
    {
        return $kayitlar->zip($satirlar)
            ->groupBy(fn ($cift): int => (int) $cift[0]->credential_group_id)
            ->sortBy(fn ($ciftler) => [$ciftler->first()[0]->group?->position ?? PHP_INT_MAX, $ciftler->first()[0]->group?->name])
            ->map(function ($ciftler) use ($sirala): array {
                $satirlar = $ciftler->map(fn ($cift): array => $cift[1])->values();
                $grup = $ciftler->first()[0]->group;
                $sirayaGirer = $sirala && $grup !== null && ! $grup->is_personal;

                return [
                    'param' => $grup?->uuid ?? self::GRUPSUZ,
                    'orderId' => $sirayaGirer ? $grup->uuid : null,
                    'draggable' => $sirayaGirer ? 'true' : null,
                    'name' => (string) ($grup?->name ?? 'Grupsuz'),
                    'count' => $satirlar->count(),
                    'critical' => $satirlar->where('critical', '>', 0)->count(),
                    'rows' => $satirlar->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** @param  Collection<int, array<string, mixed>>  $satirlar */
    private function bolumOzeti(Collection $satirlar): string
    {
        $parcalar = [$satirlar->count().' makine'];
        $kritik = $satirlar->where('critical', '>', 0)->count();
        $bekleyen = $satirlar->where('pending', '>', 0)->count();
        $sorunlu = $satirlar->whereNotNull('checkError')->count();

        if ($kritik > 0) {
            $parcalar[] = "{$kritik} kritik bekleyen";
        }

        if ($bekleyen > $kritik) {
            $parcalar[] = ($bekleyen - $kritik).' güncelleme bekleyen';
        }

        if ($sorunlu > 0) {
            $parcalar[] = "{$sorunlu} denetlenemedi";
        }

        return implode(' · ', $parcalar);
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
            'os' => $durum?->os_name,
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
            /* Düğme yalnızca gerektiğinde: kilitli ya da kurulumu süren makinede yok (karar OsRebooter'da da). */
            'canReboot' => (bool) $durum?->reboot_required && $kilit === null && ! $mesgul && $user->can('osReboot', $kayit),
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
}
