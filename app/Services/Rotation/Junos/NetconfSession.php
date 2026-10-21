<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

use App\Services\Rotation\Junos\Framing\EndOfMessageFraming;
use App\Services\Rotation\Junos\Framing\FramingNegotiator;
use App\Services\Rotation\Junos\Framing\MessageFraming;
use App\Services\Rotation\Junos\Transport\NetconfTransport;
use SimpleXMLElement;
use Throwable;

/**
 * Açık bir NETCONF oturumu: hello değişimi, RPC gönderimi, kapanış.
 *
 * Oturum kurmak pahalıdır (cihazda mgd fork'lanır, ~2 sn), RPC ucuzdur. Açılan
 * oturum işi bitince MUTLAKA kapatılır; açık kalan oturum cihazın RE belleğini
 * tutar.
 */
final class NetconfSession
{
    private const BASE_NAMESPACE = 'urn:ietf:params:xml:ns:netconf:base:1.0';

    private MessageFraming $framing;

    private int $messageId = 0;

    /** @var list<string> */
    private array $deviceCapabilities = [];

    /** Son gönderilen RPC'nin kök elemanı; hata iletisine "hangi çağrı" yazılır. */
    private ?string $lastRpc = null;

    public function __construct(
        private readonly NetconfTransport $transport,
        private readonly FramingNegotiator $negotiator,
    ) {
        /* Hello daima 1.0 biçiminde konuşulur (RFC 6242, bölüm 4.1). */
        $this->framing = new EndOfMessageFraming;
    }

    /**
     * Cihazın hello'sunu okur, kendi hello'sunu gönderir, çerçevelemeyi seçer.
     *
     * @param  list<string>  $clientCapabilities
     */
    public function handshake(array $clientCapabilities): void
    {
        $hello = new EndOfMessageFraming;

        $greeting = $this->parse($hello->read($this->transport));

        $this->deviceCapabilities = array_values(array_map(
            static fn (SimpleXMLElement $node): string => trim((string) $node),
            $greeting->xpath('//*[local-name()="capability"]') ?: [],
        ));

        $this->transport->write($hello->encode($this->helloMessage($clientCapabilities)));

        $this->framing = $this->negotiator->negotiate($this->deviceCapabilities, $clientCapabilities);
    }

    /**
     * Bir RPC gönderir, yanıtı döndürür.
     *
     * @param  string  $body  <rpc> etiketinin içine konacak XML
     *
     * @throws NetconfException Cihaz rpc-error (severity: error) döndürürse
     */
    public function rpc(string $body): SimpleXMLElement
    {
        $this->messageId++;

        $this->lastRpc = preg_match('/^\s*<\s*([A-Za-z0-9_.:-]+)/', $body, $m) === 1 ? $m[1] : null;

        $this->transport->write($this->framing->encode(sprintf(
            '<rpc xmlns="%s" message-id="%d">%s</rpc>',
            self::BASE_NAMESPACE,
            $this->messageId,
            $body,
        )));

        $reply = $this->parse($this->framing->read($this->transport));

        $this->guardAgainstError($reply);

        return $reply;
    }

    /**
     * Oturumu kapatır. close-session başarısız olsa da taşıma kapatılır:
     * cihazda mgd süreci asılı kalmamalı.
     */
    public function close(): void
    {
        try {
            $this->transport->write($this->framing->encode(sprintf(
                '<rpc xmlns="%s" message-id="%d"><close-session/></rpc>',
                self::BASE_NAMESPACE,
                ++$this->messageId,
            )));
        } catch (Throwable) {
            /* Bağlantı zaten kopmuşsa kapatma mesajı gönderilemez; sorun değil. */
        } finally {
            $this->transport->close();
        }
    }

    /** @return list<string> */
    public function deviceCapabilities(): array
    {
        return $this->deviceCapabilities;
    }

    public function framingCapability(): string
    {
        return $this->framing->capability();
    }

    /** @param  list<string>  $capabilities */
    private function helloMessage(array $capabilities): string
    {
        $lines = array_map(
            static fn (string $capability): string => '<capability>'.htmlspecialchars($capability, ENT_XML1).'</capability>',
            $capabilities,
        );

        return sprintf('<hello xmlns="%s"><capabilities>%s</capabilities></hello>', self::BASE_NAMESPACE, implode('', $lines));
    }

    /**
     * Cihazdan gelen XML GÜVENİLMEZ veridir: DOCTYPE reddedilir (NETCONF
     * yanıtında hiç bulunmaz; kabul etmek yalnızca varlık genişletmesine kapı
     * açardı) ve LIBXML_NONET çözümleyicinin dışarı çıkmasını engeller.
     */
    private function parse(string $xml): SimpleXMLElement
    {
        if ($xml === '') {
            throw new NetconfException('Cihazdan boş NETCONF yanıtı geldi.');
        }

        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new NetconfException('NETCONF yanıtında doküman türü bildirimi var; böyle bir yanıt kabul edilmez.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($element === false) {
            throw new NetconfException('NETCONF yanıtı çözümlenemedi.');
        }

        return $element;
    }

    private function guardAgainstError(SimpleXMLElement $reply): void
    {
        $fatal = [];

        foreach ($reply->xpath('//*[local-name()="rpc-error"]') ?: [] as $node) {
            $error = RpcError::fromXml($node);

            /*
             * Junos uyarıları da rpc-error olarak döner ("uncommitted changes
             * will be discarded on exit" gibi) ve işi durdurmamalı; yalnızca
             * severity "error" olanlar istisnaya çevrilir.
             */
            if (! $error->isWarning()) {
                $fatal[] = $error;
            }
        }

        if ($fatal !== []) {
            throw NetconfException::fromRpcErrors($fatal, $this->lastRpc);
        }
    }
}
