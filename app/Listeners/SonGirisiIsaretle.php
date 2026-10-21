<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * SON GİRİŞ ZAMANI — hangi kapıdan girilirse girilsin.
 *
 * Alan yalnızca panelin KENDİ parola girişinde yazılırsa (LoginRequest)
 * merkezle (KYM) giriş yapanlarda hiç yazılmaz: denetim kaydında giriş
 * görünürken kullanıcılar ekranında "Son giriş —" kalır. Kayıt, EKRANIN
 * BAKTIĞI YERE yazılmalı.
 *
 * Kural TEK YERDE ve olaya bağlı: `Auth::attempt` da `Auth::login` de
 * bu olayı üretir, dolayısıyla yeni bir giriş yolu eklendiğinde burayı
 * güncellemek gerekmez.
 *
 * SESSİZ KAYDEDİLİR (`saveQuietly`): giriş, kaydın kendisinde bir değişiklik
 * değil; model olaylarını tetiklemesi denetim kaydına "kullanıcı güncellendi"
 * satırı düşürürdü.
 */
class SonGirisiIsaretle
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
