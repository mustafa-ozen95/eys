<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos\Framing;

/**
 * Cihazın ve istemcinin bildirdiği yeteneklerden ortak en yüksek çerçeveleme
 * sürümünü seçer. Junos 12.3 yalnızca 1.0, 24.4 ise 1.1 de bildirir.
 */
final class FramingNegotiator
{
    /**
     * @param  list<string>  $deviceCapabilities  Cihazın hello'sundan
     * @param  list<string>  $clientCapabilities  İstemcinin bildirdikleri
     */
    public function negotiate(array $deviceCapabilities, array $clientCapabilities): MessageFraming
    {
        foreach ([new ChunkedFraming, new EndOfMessageFraming] as $framing) {
            $capability = $framing->capability();

            if (in_array($capability, $deviceCapabilities, true) && in_array($capability, $clientCapabilities, true)) {
                return $framing;
            }
        }

        /*
         * Cihaz hiçbir base yeteneği bildirmediyse en eskisi varsayılır:
         * RFC 6242 öncesi davranış budur ve eski Junos bu şekilde çalışır.
         */
        return new EndOfMessageFraming;
    }
}
