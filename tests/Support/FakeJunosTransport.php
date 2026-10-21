<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Rotation\Junos\Framing\EndOfMessageFraming;
use App\Services\Rotation\Junos\NetconfClient;
use App\Services\Rotation\Junos\NetconfException;
use App\Services\Rotation\Junos\Transport\NetconfTransport;

/**
 * FakeJunosDevice'a bağlı bellek içi taşıma. Gerçek ağ yoktur.
 *
 * Hello her zaman 1.0 biçimindedir (RFC 6242, 4.1); sonrası cihazın
 * bildirdiği ve istemciyle kesişen sürümde.
 */
final class FakeJunosTransport implements NetconfTransport
{
    private string $buffer = '';

    private string $user = '';

    private bool $chunked = false;

    private bool $open = false;

    /** @var array{pending: list<array<string, mixed>>} */
    private array $state = ['pending' => []];

    public function __construct(private readonly FakeJunosDevice $device) {}

    public function open(string $host, int $port, string $username, string $password, int $timeout): void
    {
        $this->device->authenticate($username, $password);

        $this->user = $username;
        $this->open = true;

        $caps = ['urn:ietf:params:netconf:base:1.0'];

        if ($this->device->framing === 'chunked') {
            $caps[] = 'urn:ietf:params:netconf:base:1.1';
        }

        $this->buffer = (new EndOfMessageFraming)->encode(
            '<hello xmlns="urn:ietf:params:xml:ns:netconf:base:1.0"><capabilities>'
            .implode('', array_map(fn (string $c): string => "<capability>{$c}</capability>", $caps))
            .'</capabilities><session-id>1</session-id></hello>'
        );
    }

    public function write(string $data): void
    {
        if (! $this->open) {
            throw new NetconfException('NETCONF oturumu açık değil.');
        }

        $this->device->rawRequests[] = $data;

        if (str_contains($data, '<hello')) {
            /* İstemci 1.1'i bildirdiyse ve cihaz da destekliyorsa sonrası parçalıdır. */
            $this->chunked = $this->device->framing === 'chunked'
                && str_contains($data, NetconfClient::CAPABILITIES[1]);

            return;
        }

        $framing = $this->chunked ? $this->device->framingInstance() : new EndOfMessageFraming;
        $xml = $framing->decode($data);

        $rpc = simplexml_load_string($xml);
        $body = $this->device->handle($this->user, $rpc, $this->state);

        $this->buffer .= $framing->encode(
            '<rpc-reply xmlns="urn:ietf:params:xml:ns:netconf:base:1.0" message-id="'.$rpc['message-id'].'">'.$body.'</rpc-reply>'
        );
    }

    public function readUntil(string $terminator): string
    {
        $position = strpos($this->buffer, $terminator);

        if ($position === false) {
            throw NetconfException::transport('Bağlantı kapandı.', 'closed')->withStage('read');
        }

        return $this->take($position + strlen($terminator));
    }

    public function readExactly(int $bytes): string
    {
        if (strlen($this->buffer) < $bytes) {
            throw NetconfException::transport('Bağlantı kapandı.', 'closed')->withStage('read');
        }

        return $this->take($bytes);
    }

    public function close(): void
    {
        $this->open = false;
    }

    private function take(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }
}
