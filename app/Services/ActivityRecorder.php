<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Denetim kaydını yazan tek yer.
 *
 * Kaydı her controller kendi yazsaydı biri IP'yi, biri gerekçeyi unuturdu.
 * Kural kopyalanmaz, paylaştırılır.
 */
class ActivityRecorder
{
    public function __construct(
        private Request $request,
        private ActivityChain $chain,
    ) {}

    public function record(
        ActivityAction $action,
        string $summary,
        ?Model $subject = null,
        ?string $reason = null,
        ?User $actor = null,
    ): ActivityLog {
        $actor ??= $this->request->user();

        /*
         * BOŞ GEREKÇE = GEREKÇE YOK.
         *
         * Gerekçe artık yalnızca "kritik" işaretli kayıtlarda zorunlu (bkz.
         * App\Support\ReasonRule); zorunlu olmadığı yerde form boş bir
         * dize gönderir. Boş dize kayda yazılsaydı denetim ekranında "gerekçe
         * verilmiş ama içi boş" gibi görünürdü — yokluk açıkça yokluk olarak
         * dursun. Normalleştirme TEK YERDE: gerekçe yazan bütün uçlar buradan
         * geçiyor.
         */
        $reason = blank($reason) ? null : $reason;

        /*
         * Kayıt doğrudan yazılmaz, ZİNCİRE eklenir: satırın sonradan
         * değiştirilmediği ancak böyle kanıtlanabilir.
         */
        return $this->chain->append([
            'user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name ?? 'bilinmiyor',
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => $summary,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => str($this->request->userAgent() ?? '')->limit(250)->value(),
        ]);
    }
}
