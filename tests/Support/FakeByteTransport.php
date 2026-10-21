<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Rotation\Junos\NetconfException;
use App\Services\Rotation\Junos\Transport\NetconfTransport;

/**
 * Önceden doldurulmuş bir bayt tamponu — çerçeveleme birimleri için.
 *
 * Gerçek taşımanın tampon davranışını taklit eder: readUntil ve readExactly
 * AYNI tampondan okur. Tampon bitince bağlantı kopmuş gibi davranır.
 */
final class FakeByteTransport implements NetconfTransport
{
    /** @var list<string> */
    public array $written = [];

    public function __construct(private string $buffer = '') {}

    public function open(string $host, int $port, string $username, string $password, int $timeout): void {}

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function readUntil(string $terminator): string
    {
        $position = strpos($this->buffer, $terminator);

        if ($position === false) {
            throw NetconfException::transport('Bağlantı kapandı.', 'closed');
        }

        return $this->take($position + strlen($terminator));
    }

    public function readExactly(int $bytes): string
    {
        if (strlen($this->buffer) < $bytes) {
            throw NetconfException::transport('Bağlantı kapandı.', 'closed');
        }

        return $this->take($bytes);
    }

    public function close(): void {}

    public function remaining(): string
    {
        return $this->buffer;
    }

    private function take(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }
}
