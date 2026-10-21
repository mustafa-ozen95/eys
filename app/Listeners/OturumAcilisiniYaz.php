<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Kimlik\OrtakCikis;
use Illuminate\Auth\Events\Login;

/**
 * Oturumun açıldığı anı oturuma yazar: merkezdeki son çıkıştan önce
 * açılmış oturum kapanır (bkz. OrtakCikis). Her giriş yolunda çalışır.
 */
class OturumAcilisiniYaz
{
    public function handle(Login $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put(OrtakCikis::OTURUM_ANAHTARI, OrtakCikis::simdiMs());
        }
    }
}
