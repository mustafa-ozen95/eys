<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers\Concerns;

use App\Enums\AccessType;

/**
 * Makinenin hesabı KASADAKİ SSH KAYDIDIR — Linux tarafındaki sürücüler için.
 *
 * Plesk (Linux), mailcow süper yöneticisi ve rspamd'nin üçünde de aynı cevap
 * veriliyordu; üç yere yazılsaydı biri değiştiğinde diğerleri sessizce eski
 * kalırdı. Windows'taki Plesk aynı soruya "RDP kaydı" diye cevap veriyor
 * (bkz. PleskWindowsPasswordDriver).
 */
trait SecerMakineSshKaydi
{
    /** @return list<AccessType> */
    public function makineErisimTurleri(): array
    {
        return [AccessType::Ssh];
    }
}
