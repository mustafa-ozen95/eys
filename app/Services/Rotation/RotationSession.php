<?php

declare(strict_types=1);

namespace App\Services\Rotation;

/**
 * Sürücünün açık bağlantısı.
 *
 * Oturumu açan kimlik de taşınır: ayrıcalık yükseltme (sudo) parolayı yeniden
 * ister ve sürücü onu başka yerden bulamaz.
 */
class RotationSession
{
    /** @param  callable(): void  $closer */
    public function __construct(
        public readonly mixed $handle,
        public readonly string $username,
        public readonly string $password,
        private $closer,
    ) {}

    public function close(): void
    {
        ($this->closer)();
    }
}
