<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

/**
 * NETCONF oturumu açılacak hedef: adres, port ve kimlik.
 *
 * Parola düz metindir ve hiçbir yere yazılmaz; nesne tur boyunca bellekte
 * yaşar. `SensitiveParameter` işareti, bir istisna izinde parolanın
 * görünmesini engeller.
 */
final readonly class NetconfTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        #[\SensitiveParameter] public string $password,
    ) {}
}
