<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Denetim kaydının sorgu kurucusu.
 *
 * Tek işi silmeyi kapatmak. Model olayı (deleting) yalnızca satır satır
 * silmeyi yakalar; toplu silme olayları atladığı için yasak orada boşluk
 * veriyordu. İki yol da aynı kapıdan geçer: ActivityLog::prune().
 *
 * @template TModel of ActivityLog
 *
 * @extends Builder<TModel>
 */
class ActivityLogBuilder extends Builder
{
    /** @return int Silinen satır sayısı; budama dışında her zaman 0. */
    public function delete(): int
    {
        return ActivityLog::pruning() ? parent::delete() : 0;
    }
}
