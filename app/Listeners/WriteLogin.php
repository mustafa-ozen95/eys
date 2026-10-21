<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Identity\SharedLogout;
use Illuminate\Auth\Events\Login;

/**
 * Oturumun açıldığı anı oturuma yazar: merkezdeki son çıkıştan önce
 * açılmış oturum kapanır (bkz. SharedLogout). Her giriş yolunda çalışır.
 */
class WriteLogin
{
    public function handle(Login $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put(SharedLogout::OTURUM_ANAHTARI, SharedLogout::simdiMs());
        }
    }
}
