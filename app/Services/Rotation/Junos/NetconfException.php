<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

use RuntimeException;

/**
 * Junos ile NETCONF konuşulamadığında ya da cihaz hata döndürdüğünde fırlar.
 *
 * Sürücü bunu yakalayıp RotationException'a çevirir (bkz. JunosPasswordDriver);
 * yukarı katmanlar bu sınıfı tanımaz. Mesajda PAROLA bulunmaz: taşıma katmanı
 * ve oturum yalnızca adres, aşama ve cihazın kendi hata metnini taşır.
 *
 * Hatanın CİNSİ ayrıca tutulur, çünkü sürücünün verdiği karar buna bağlı:
 * "kimlik reddedildi" doğrulamada `false` demektir, "cihaza ulaşılamadı" ise
 * `false` DEĞİLDİR (bkz. DeviceUnreachableException).
 */
class NetconfException extends RuntimeException
{
    /** rpc | timeout | closed | auth | lock | framing | candidate-dirty */
    private string $kind = 'unknown';

    private ?string $stage = null;

    /** @param  list<RpcError>  $errors */
    public static function fromRpcErrors(array $errors, ?string $rpc = null): self
    {
        $exception = new self('Cihaz RPC hatası döndürdü: '.implode(' | ', array_map(
            static fn (RpcError $error): string => $error->line(),
            $errors,
        )).($rpc === null ? '' : " [{$rpc}]"));

        $exception->kind = 'rpc';

        return $exception;
    }

    /**
     * Taşıma katmanı hataları. `$previous` BİLEREK alınmaz: phpseclib
     * istisnasının izi, bağlantıyı açan çağrının bağımsız değişkenlerini
     * (parola dahil) taşıyabilir ve günlüğe oradan sızardı.
     */
    public static function transport(string $message, string $kind): self
    {
        $exception = new self($message);
        $exception->kind = $kind;

        return $exception;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function stage(): ?string
    {
        return $this->stage;
    }

    public function withStage(string $stage): self
    {
        $this->stage ??= $stage;

        return $this;
    }

    /**
     * Cihaza ULAŞILAMADI (kimlik reddi değil): zaman aşımı ya da kapanan kanal.
     * Ayrım önemli: ulaşılamamak "parola yanlış" demek değildir.
     */
    public function unreachable(): bool
    {
        return in_array($this->kind, ['timeout', 'closed'], true);
    }
}
