<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos\Framing;

use App\Services\Rotation\Junos\NetconfException;
use App\Services\Rotation\Junos\Transport\NetconfTransport;

/**
 * NETCONF mesaj çerçeveleme sürümü (RFC 6242).
 *
 * base:1.0 mesajları "]]>]]>" ile ayırır (Junos 12.3 yalnızca bunu bilir),
 * base:1.1 uzunluk önekli parçalara böler (Junos 24.4 ve benzeri). Hangisinin
 * konuşulacağı hello değişiminden sonra bellidir.
 *
 * MESAJI OKUMAK da çerçevelemenin işidir: 1.1'de sınırı ayırıcı değil UZUNLUK
 * belirler ve parça verisi "\n##\n" dizisini içerebilir (Junos yapılandırma
 * metni "##" ile başlayan yorum satırları taşır).
 */
interface MessageFraming
{
    /** Bu çerçevelemenin yetenek kimliği. */
    public function capability(): string;

    /** Gönderilecek XML'i çerçeveler. */
    public function encode(string $xml): string;

    /**
     * Taşımadan TAM BİR mesaj okur ve çerçevesini soyar.
     *
     * @throws NetconfException
     */
    public function read(NetconfTransport $transport): string;

    /**
     * Elde hazır duran bir çerçeveyi soyar.
     *
     * @throws NetconfException
     */
    public function decode(string $raw): string;
}
