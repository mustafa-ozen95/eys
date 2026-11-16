<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Enums\ActivityAction;
use App\Enums\BadgeVariant;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sistem güncellemeleri ekranında sağdaki kart: seçili makinenin durumu,
 * bekleyen paketleri ve GÜNCELLEME GEÇMİŞİ.
 *
 * Geçmiş etkinlik kaydından okunur (kurulum sonuçları ve kilit
 * değişiklikleri); ayrı bir tablo tutulmaz. Denetimler kayda yazılmaz,
 * yalnızca sonuncusu durur — kart onu üstte gösterir.
 */
class OsUpdateDetail
{
    /** Kartta gösterilen geçmiş satırı; tamamı kayıtlar ekranında. */
    private const GECMIS = 15;

    public function __construct(private OsUpdateTargets $targets) {}

    /**
     * @param  array<string, mixed>  $satir  OsUpdateListing'in bu makine için kurduğu satır
     * @param  Collection<int, Credential>  $gorunenler  kullanıcının gördüğü hedef kayıtlar
     * @return array<string, mixed>
     */
    public function build(User $user, Credential $kayit, array $satir, Collection $gorunenler): array
    {
        $gecmisGorur = $satir['canApply'] || $satir['canLock'] || $satir['canReboot'] || $user->can('viewAny', ActivityLog::class);

        return [
            ...$satir,
            'groupName' => collect([$kayit->group?->name, $kayit->altGrup?->name])->filter()->implode(' · '),
            'facts' => array_filter([
                'Adres' => $kayit->host.':'.OsUpdateTargets::sshPort($kayit),
                'İşletim sistemi' => $satir['os'],
                'Paket yöneticisi' => $satir['manager'],
                'Son denetim' => $satir['checkedAt'] === null ? null : $satir['checkedAt'].' ('.$satir['checkedAgo'].')',
            ], fn (?string $deger): bool => filled($deger)),
            'historyVisible' => $gecmisGorur,
            'history' => $gecmisGorur ? $this->gecmis($kayit, $gorunenler) : [],
            'logsLink' => $user->can('viewAny', ActivityLog::class) ? route('logs.index', ['kayit' => $kayit->uuid]) : null,
        ];
    }

    /**
     * Makinenin kurulum ve kilit girdileri, yeniden eskiye.
     *
     * Kilit kardeş kayıtta konmuş olabilir (aynı makine, başka kayıt);
     * makinenin geçmişi onları da içerir. Yalnızca kullanıcının GÖRDÜĞÜ
     * kardeşler: görmediği bir kaydın gerekçesi buradan sızmasın.
     *
     * @param  Collection<int, Credential>  $gorunenler
     * @return list<array{at: string, ago: string, label: string, variant: BadgeVariant, summary: string, actor: string, reason: ?string}>
     */
    private function gecmis(Credential $kayit, Collection $gorunenler): array
    {
        $gorunur = $gorunenler->modelKeys();
        $kimlikler = $this->targets->kardesler($kayit)
            ->modelKeys();
        $kimlikler = array_values(array_intersect([...$kimlikler, $kayit->getKey()], $gorunur));

        return ActivityLog::query()
            ->where('subject_type', $kayit->getMorphClass())
            ->whereIn('subject_id', $kimlikler)
            ->whereIn('action', [ActivityAction::OsUpdate, ActivityAction::OsUpdateLock, ActivityAction::OsReboot])
            ->latest('id')
            ->limit(self::GECMIS)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'at' => $log->created_at->translatedFormat('d.m.Y H:i'),
                'ago' => $log->created_at->diffForHumans(),
                'label' => $log->action->label(),
                'variant' => $log->action->badge(),
                'summary' => $log->summary,
                'actor' => $log->actor_name,
                'reason' => $log->reason,
            ])
            ->all();
    }
}
