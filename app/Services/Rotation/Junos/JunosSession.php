<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

/**
 * Sürücünün RotationSession içinde taşıdığı açık Junos oturumu.
 *
 * Hedef de taşınır: geri alınabilir commit'in onayı YENİ bir oturumda aynı
 * kimlikle verilir ve kimliği başka yerden bulmanın yolu yoktur.
 */
final class JunosSession
{
    public function __construct(
        public readonly NetconfTarget $target,
        public readonly NetconfSession $netconf,
    ) {}
}
