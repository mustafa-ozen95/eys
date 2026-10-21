<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Rol yönetimi yalnızca yöneticidedir (Gate::before).
 *
 * Burada tanımlı olan tek şey yöneticinin de yapamayacaklarıdır: yerleşik bir
 * rolü ya da kullanıcısı olan bir rolü silmek. Kullanıcı rolsüz kalırsa
 * hiçbir kontrolden geçemez.
 */
class RolePolicy
{
    /** Yöneticinin de geçemediği yetkiler (bkz. AppServiceProvider). */
    public const GUARDED = ['delete'];

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Role $role): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $role): bool
    {
        return false;
    }

    public function delete(User $user, Role $role): bool
    {
        /* Yönetici olmayan için Gate::before null döner; karar buraya düşer. */
        return $user->isAdmin() && $role->isDeletable();
    }
}
