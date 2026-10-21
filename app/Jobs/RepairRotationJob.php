<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PasswordRotation;
use App\Models\User;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationLauncher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Yarıda kalmış turu kuyrukta kapatır.
 *
 * Onarım uzak makineye bağlanır: sırayla üç kimlik dener ve kurtarma hesabını
 * siler. Her deneme bağlantı zaman aşımı kadar sürebilir, yani istek içinde
 * çalıştığında tarayıcı dakikalarca bekliyordu — döndürmenin kendisi zaten
 * kuyrukta, onarımın da orada olması gerekirdi.
 *
 * TEKRAR DENENMEZ (tries = 1): yarıda kalmış bir işi körlemesine tekrarlamak
 * makinede ikinci bir kurtarma hesabı bırakabilir; ikinci deneme insan
 * kararıyla başlar.
 *
 * AYNI TUR İÇİN İKİNCİ İŞ SIRAYA GİRMEZ. Döndürmeyle çakışmayı ise kuyruk
 * değil, ikisinin paylaştığı kayıt kilidi önler (bkz. PasswordRotator).
 */
class RepairRotationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(
        private PasswordRotation $rotation,
        private ?User $actor = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->rotation->getKey();
    }

    public function handle(PasswordRotator $rotator): void
    {
        /* İz iş bitince düşer: kayıt paneli yoklamayı bırakır, sayfa tazelenir. */
        try {
            $rotator->repair($this->rotation, $this->actor);
        } finally {
            RotationLauncher::forgetPending($this->rotation->credential);
        }
    }
}
