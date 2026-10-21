<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Activity\ActivityChain;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Zinciri kuyrukta doğrular.
 *
 * Doğrulama BÜTÜN satırları baştan özetler; yüz binlik bir kayıtta bu
 * dakikalar sürer. İstek içinde çalıştığında tarayıcı bekliyor, PHP zaman
 * aşımına giriyor ve doğrulama hiç bitmiyordu.
 *
 * Sonuç önbelleğe yazılır; ekran onu okur (bkz. ActivityChain::lastVerification).
 *
 * TEKRAR DENENMEZ ve AYNI ANDA BİR TANE çalışır: aynı işi iki kez yapmak
 * yalnızca sunucuyu meşgul eder, sonucu değiştirmez.
 */
class VerifyActivityChainJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** Aynı anda tek doğrulama; kilit işin kendisinden uzun yaşamamalı. */
    public int $uniqueFor = 950;

    public function handle(ActivityChain $chain): void
    {
        try {
            $chain->verify();
        } finally {
            $chain->finishRunning();
        }
    }

    /** İş kuyrukta düşerse ekran sonsuza dek "sürüyor" demesin. */
    public function failed(): void
    {
        app(ActivityChain::class)->finishRunning();
    }
}
