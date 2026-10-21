<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos\Framing;

use App\Services\Rotation\Junos\Transport\NetconfTransport;

/**
 * NETCONF base:1.0 çerçevelemesi (RFC 6242, bölüm 4.3): mesajlar "]]>]]>" ile
 * ayrılır.
 *
 * Hello mesajı sürümden bağımsız olarak DAİMA bu biçimde gönderilir ve
 * okunur; hangi sürümün konuşulacağı ancak hello'dan sonra bilinebilir.
 */
final class EndOfMessageFraming implements MessageFraming
{
    public const DELIMITER = ']]>]]>';

    public function capability(): string
    {
        return 'urn:ietf:params:netconf:base:1.0';
    }

    public function encode(string $xml): string
    {
        return trim($xml)."\n".self::DELIMITER."\n";
    }

    public function read(NetconfTransport $transport): string
    {
        return $this->decode($transport->readUntil(self::DELIMITER));
    }

    public function decode(string $raw): string
    {
        $position = strpos($raw, self::DELIMITER);

        return trim($position === false ? $raw : substr($raw, 0, $position));
    }
}
