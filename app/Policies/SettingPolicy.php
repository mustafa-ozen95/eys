<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

/**
 * Ayarlar yalnızca yöneticiye açıktır (Gate::before).
 *
 * Buradaki ayarlar güvenlik davranışını değiştiriyor — giriş denemesi
 * sınırı, oturum süresi, döndürmenin açık olup olmaması. Bunları
 * değiştirebilen, paneli yönetiyor demektir.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function update(User $user, ?Setting $setting = null): bool
    {
        return false;
    }
}
