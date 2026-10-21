<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos\Framing;

use App\Services\Rotation\Junos\NetconfException;
use App\Services\Rotation\Junos\Transport\NetconfTransport;

/**
 * NETCONF base:1.1 çerçevelemesi (RFC 6242, bölüm 4.2).
 *
 *     \n#<uzunluk>\n<veri>...\n##\n
 *
 * SINIRI UZUNLUK BELİRLER, ayırıcı değil. Parça verisi "\n##\n" dizisini
 * kendi içinde taşıyabilir (Junos yapılandırma çıktısının "## Last changed"
 * satırı); ayırıcıya bakan bir okuyucu mesajı ortasından keser. Bu yüzden
 * başlık okunur, uzunluk ayrıştırılır ve TAM o kadar bayt tüketilir.
 */
final class ChunkedFraming implements MessageFraming
{
    public const DELIMITER = "\n##\n";

    /** Bozuk bir başlık gigabaytlarca bayt beklettirmesin diye üst sınır. */
    private const MAX_CHUNK = 16 * 1024 * 1024;

    public function capability(): string
    {
        return 'urn:ietf:params:netconf:base:1.1';
    }

    public function encode(string $xml): string
    {
        $payload = trim($xml);

        return "\n#".strlen($payload)."\n".$payload.self::DELIMITER;
    }

    public function read(NetconfTransport $transport): string
    {
        $xml = '';

        while (true) {
            $header = $this->header($transport);

            if ($header === '##') {
                return trim($xml);
            }

            $xml .= $transport->readExactly($this->chunkSize($header));
        }
    }

    /**
     * Parça başlığı: "#<uzunluk>" ya da son parça işareti "##". Önündeki
     * satır sonu bir önceki parçanın ayırıcısıdır; boş satırlar atlanır.
     */
    private function header(NetconfTransport $transport): string
    {
        for ($deneme = 0; $deneme < 3; $deneme++) {
            $line = trim($transport->readUntil("\n"));

            if ($line !== '') {
                return $line;
            }
        }

        throw NetconfException::transport('NETCONF parça başlığı bulunamadı: çerçeve bozuk.', 'framing');
    }

    private function chunkSize(string $header): int
    {
        $length = substr($header, 1);

        if (! str_starts_with($header, '#') || ! ctype_digit($length) || (int) $length < 1) {
            throw NetconfException::transport("Geçersiz NETCONF parça başlığı: {$header}", 'framing');
        }

        if ((int) $length > self::MAX_CHUNK) {
            throw NetconfException::transport("NETCONF parçası kabul edilemeyecek kadar büyük: {$length} bayt.", 'framing');
        }

        return (int) $length;
    }

    public function decode(string $raw): string
    {
        $xml = '';
        $offset = 0;

        while (true) {
            $start = strpos($raw, "\n#", $offset);

            if ($start === false) {
                throw NetconfException::transport('NETCONF parçası bulunamadı: çerçeve bozuk.', 'framing');
            }

            if (str_starts_with(substr($raw, $start, 4), self::DELIMITER)) {
                return trim($xml);
            }

            $lengthEnd = strpos($raw, "\n", $start + 2);

            if ($lengthEnd === false) {
                throw NetconfException::transport('NETCONF parça uzunluğu okunamadı.', 'framing');
            }

            $length = substr($raw, $start + 2, $lengthEnd - $start - 2);

            if (! ctype_digit($length) || (int) $length < 1) {
                throw NetconfException::transport("Geçersiz NETCONF parça uzunluğu: {$length}", 'framing');
            }

            $xml .= substr($raw, $lengthEnd + 1, (int) $length);
            $offset = $lengthEnd + 1 + (int) $length;
        }
    }
}
