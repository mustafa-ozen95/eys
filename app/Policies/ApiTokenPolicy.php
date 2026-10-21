<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;

/**
 * API jetonu yönetimi yalnızca yöneticidedir (Gate::before).
 *
 * NEDEN SADECE YÖNETİCİ: jeton, sahibinin yetkisini kalıcı bir anahtara
 * dönüştürür. Başkası adına jeton çıkarabilen biri, o kişinin yetkisini
 * istediği yere taşıyabilir — bu, yetki dağıtmakla aynı ağırlıkta bir iştir.
 *
 * Buradaki yöntemler bilinçli olarak false döner: karar Gate::before'da
 * verilir ve yönetici olmayan hiçbir yol buraya ulaşmaz. Yöneticinin de
 * yapamayacağı bir şey çıkarsa o kural burada yazılır.
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, ApiToken $token): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ApiToken $token): bool
    {
        return false;
    }

    public function delete(User $user, ApiToken $token): bool
    {
        return false;
    }
}
