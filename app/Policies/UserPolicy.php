<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Kullanıcı yönetimi yalnızca yöneticidedir (Gate::before).
 *
 * Burada tanımlı olan tek şey yöneticinin de yapamayacaklarıdır: kendi
 * hesabını silmek ve kendi rolünü değiştirmek. İkisi de paneli yönetilemez
 * hâle getirebilir.
 */
class UserPolicy
{
    /**
     * Yöneticinin de geçemediği yetkiler.
     *
     * Gate::before yöneticiyi bütün policy'lerin ÜSTÜNDEN atlatır; buradaki
     * iki kural o atlamanın istisnasıdır (bkz. AppServiceProvider). Liste
     * kuralın yanında duruyor ki biri eklenirken diğeri unutulmasın.
     *
     * @var list<string>
     */
    public const SELF_GUARDED = ['delete', 'updateRole'];

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, User $target): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $target): bool
    {
        return false;
    }

    public function delete(User $user, User $target): bool
    {
        /*
         * "Yönetici mi" sorusu burada da sorulur. Gate::before yöneticiyi
         * zaten geçirir; ama yönetici OLMAYAN için null döner ve karar bu
         * metoda düşer. Yalnızca "kendini silme" denetimi yetmez: yönetici
         * olmayan kimse başkasını (yöneticiyi de) silemez.
         */
        return $user->isAdmin() && ! $user->is($target);
    }

    /**
     * Rol değiştirme.
     *
     * Kendi rolünü kimse değiştiremez — yönetici bile. Son yöneticinin
     * kendini okuyucuya çevirmesi paneli sahipsiz bırakır.
     */
    public function updateRole(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }
}
