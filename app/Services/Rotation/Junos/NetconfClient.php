<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos;

use App\Services\Rotation\Junos\Framing\FramingNegotiator;
use App\Services\Rotation\Junos\Transport\NetconfTransport;
use App\Services\Rotation\Junos\Transport\SshSubsystemTransport;
use Closure;

/**
 * Bir Junos cihazı için NETCONF oturumu açar.
 *
 * Taşıma bir FABRİKADAN gelir: her oturum kendi taşımasını ister (bir taşıma
 * yalnızca bir oturum taşır) ve testler fabrikayı sahte cihazla değiştirir —
 * üretim sınıfı testte asla gerçek bağlantı açmamalı.
 */
final class NetconfClient
{
    /** Hello'da bildirilen yetenekler; çerçeveleme bunların cihazınkiyle kesişiminden seçilir. */
    public const CAPABILITIES = [
        'urn:ietf:params:netconf:base:1.0',
        'urn:ietf:params:netconf:base:1.1',
    ];

    /** @param  Closure(): NetconfTransport  $transportFactory */
    public function __construct(
        private readonly Closure $transportFactory,
        private readonly FramingNegotiator $negotiator = new FramingNegotiator,
        private readonly int $timeout = 60,
    ) {}

    public static function ssh(?int $timeout = null): self
    {
        return new self(
            static fn (): NetconfTransport => new SshSubsystemTransport,
            timeout: $timeout ?? (int) config('rotation.junos.timeout', 60),
        );
    }

    /**
     * Oturumu açar ve hello'yu tamamlar. Kapatmak ÇAĞIRANIN işidir.
     *
     * @throws NetconfException
     */
    public function connect(NetconfTarget $target): NetconfSession
    {
        $transport = ($this->transportFactory)();
        $transport->open($target->host, $target->port, $target->username, $target->password, $this->timeout);

        $session = new NetconfSession($transport, $this->negotiator);

        try {
            $session->handshake(self::CAPABILITIES);
        } catch (\Throwable $e) {
            /* Hello'su gelmeyen oturum açık bırakılmaz. */
            $transport->close();

            throw $e instanceof NetconfException ? $e : NetconfException::transport('NETCONF hello değişimi başarısız.', 'closed');
        }

        return $session;
    }

    /**
     * Oturum açar, işi çalıştırır, oturumu HER DURUMDA kapatır.
     *
     * @template TReturn
     *
     * @param  Closure(NetconfSession): TReturn  $callback
     * @return TReturn
     *
     * @throws NetconfException
     */
    public function runOn(NetconfTarget $target, Closure $callback): mixed
    {
        $session = $this->connect($target);

        try {
            return $callback($session);
        } finally {
            $session->close();
        }
    }
}
