<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Kullanıcı değiştirmek YENİ BİR TARAYICI açmaktır.
     *
     * Panel oturumu parolaya bağlı (AuthenticateSession): oturumda saklanan
     * parola özeti başka bir kullanıcınınkiyle uyuşmazsa oturum düşer. Testler
     * tek uygulama örneğinde art arda farklı kullanıcılarla istek atıyor ve
     * oturum deposu örnekler arasında kalıyor; önceki kullanıcının özeti
     * sonrakini çıkış yaptırırdı. Gerçekte her kullanıcının kendi çerezi,
     * dolayısıyla kendi oturumu var.
     *
     * @param  string|null  $guard
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        $this->app['session']->driver()->forget('password_hash_'.($guard ?? 'web'));

        return parent::actingAs($user, $guard);
    }
}
