<?php

declare(strict_types=1);

namespace App\Services\Rotation\Contracts;

/**
 * Kurtarma hesabının adını KENDİ belirleyen sürücü.
 *
 * Varsayılan, rotator'ın rastgele ekli adıdır (eys-kurtarma-ab12cd). Ad
 * cihazda izin listesine ya da sınıf kuralına bağlıysa ve sürücü yalnızca
 * belli bir önekle çalışacak biçimde kilitlenmişse (Junos), adı sürücü verir.
 * Ayrı bir arayüz olmasının nedeni: öteki sürücülerin hiçbirinde böyle bir
 * ihtiyaç yok; sözleşmeye zorunlu metot eklemek hepsine anlamsız gövde yazdırırdı.
 */
interface NamesRecoveryAccount
{
    public function recoveryName(): string;
}
