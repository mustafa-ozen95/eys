<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ErrorLog;
use App\Models\User;

/**
 * Hata kaydı yalnızca yöneticiye açıktır (Gate::before).
 *
 * Yığın izi kod yapısını ve dosya yollarını gösteriyor; bu bir saldırgan
 * için haritadır. Hata mesajları da bazen kayıt adı geçiriyor.
 */
class ErrorLogPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, ErrorLog $log): bool
    {
        return false;
    }

    /** Çözüldü işareti; kaydın kendisi SİLİNMEZ. */
    public function resolve(User $user, ErrorLog $log): bool
    {
        return false;
    }
}
