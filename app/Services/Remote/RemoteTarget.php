<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\AccessType;
use App\Models\Credential;

/**
 * Konsol oturumunun hedefi.
 *
 * Kasadaki kayıt ile oturum açmak için gereken bilgi aynı şey değildir:
 * kayıtta port boş olabilir, erişim türü konsola uygun olmayabilir. Bu nesne
 * yalnızca açılabilir bir hedefi temsil eder.
 */
final readonly class RemoteTarget
{
    public function __construct(
        public Credential $subject,
        public string $label,
        public string $host,
        public AccessType $protocol,
        public int $port,
        public string $username,
        public string $password,
    ) {}

    public function isSsh(): bool
    {
        return $this->protocol === AccessType::Ssh;
    }
}
