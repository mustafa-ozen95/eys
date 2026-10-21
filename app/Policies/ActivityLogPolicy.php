<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Denetim kaydı yalnızca yöneticiye açıktır (Gate::before).
 *
 * Kimin neyi ne zaman gördüğü, gören kişinin kendi kararıyla
 * gizleyebileceği bir bilgi olmamalı.
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, ActivityLog $log): bool
    {
        return false;
    }
}
