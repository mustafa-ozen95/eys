<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Rotation\Junos\Framing\ChunkedFraming;
use App\Services\Rotation\Junos\Framing\EndOfMessageFraming;
use App\Services\Rotation\Junos\Framing\MessageFraming;
use App\Services\Rotation\Junos\NetconfClient;
use App\Services\Rotation\Junos\NetconfException;
use App\Services\Rotation\Junos\Transport\NetconfTransport;
use SimpleXMLElement;

/**
 * Sahte Junos cihazı.
 *
 * Canlı cihaza BAĞLANILMAZ (bkz. .ai/rules/calisma.md); NETCONF konuşan, yerel
 * kullanıcı tablosu tutan, lock/commit/confirmed commit'i taklit eden bir
 * durum makinesidir. Testler `$events` dizisine bakar: "kim ne yaptı" sırası
 * güvenliğin kendisidir (kurtarma hesabı parola değişiminden ÖNCE açılmalı,
 * en son silinmeli).
 *
 * Hiçbir soket açılmaz: taşıma bellek içi bir tampondur.
 */
final class FakeJunosDevice
{
    /** @var array<string, array{class: ?string, password: string}> */
    public array $users;

    /** @var list<string> "<kim>: <ne>" biçiminde, sırasıyla */
    public array $events = [];

    /** Açılmaya çalışılan oturum sayısı (kimlik reddi de sayılır). */
    public int $connectAttempts = 0;

    /** Cihazın bildirdiği çerçeveleme: 'eom' (12.3) ya da 'chunked' (24.4). */
    public string $framing = 'eom';

    /** Bu kullanıcılar hesabı olsa bile giremez (çalışmayan kurtarma hesabı). */
    public array $rejectLogins = [];

    /**
     * Kullanıcı => kaç başarılı girişten SONRA reddedilsin. Parola değişimi
     * sonrası onay oturumunun açılamaması gibi "tur ortasında" arızaları sınar.
     *
     * @var array<string, int>
     */
    public array $rejectLoginAfter = [];

    /** @var array<string, int> */
    private array $logins = [];

    /** Ağ kapalı: bağlantı kurulamaz. */
    public bool $down = false;

    /** Bu RPC adı hata döndürür (ör. 'commit-configuration'). */
    public ?string $failRpc = null;

    /** @var list<string> Cihaza yazılan her isteğin ham metni (parola sızıntısı denetimi için DEĞİL, çerçeve sınaması için). */
    public array $rawRequests = [];

    private bool $confirmPending = false;

    /** @var array<string, array{class: ?string, password: string}>|null */
    private ?array $beforeConfirmed = null;

    private ?string $lockedBy = null;

    /** @param  array<string, string>  $passwords hesap => parola */
    public function __construct(array $passwords)
    {
        $this->users = array_map(
            static fn (string $p): array => ['class' => 'super-user', 'password' => $p],
            $passwords,
        );
    }

    public function client(): NetconfClient
    {
        return new NetconfClient(fn (): NetconfTransport => new FakeJunosTransport($this), timeout: 1);
    }

    /** Onay verilmeden süre doldu: cihaz kendini geri alır. */
    public function confirmTimeout(): void
    {
        if ($this->confirmPending && $this->beforeConfirmed !== null) {
            $this->users = $this->beforeConfirmed;
            $this->events[] = 'cihaz: geri alma (onay gelmedi)';
        }

        $this->confirmPending = false;
    }

    public function framingInstance(): MessageFraming
    {
        return $this->framing === 'chunked' ? new ChunkedFraming : new EndOfMessageFraming;
    }

    public function authenticate(string $user, string $password): void
    {
        $this->connectAttempts++;

        if ($this->down) {
            throw NetconfException::transport('bağlantı kurulamadı', 'closed')->withStage('connect');
        }

        if (in_array($user, $this->rejectLogins, true)
            || ! isset($this->users[$user])
            || $this->users[$user]['password'] !== $password) {
            throw NetconfException::transport('NETCONF kimlik doğrulaması başarısız.', 'auth')->withStage('connect');
        }

        $this->logins[$user] = ($this->logins[$user] ?? 0) + 1;

        if (isset($this->rejectLoginAfter[$user]) && $this->logins[$user] > $this->rejectLoginAfter[$user]) {
            throw NetconfException::transport('NETCONF kimlik doğrulaması başarısız.', 'auth')->withStage('connect');
        }
    }

    /**
     * @param  array{pending: list<array<string, mixed>>}  $state  oturuma özel aday kopya
     */
    public function handle(string $user, SimpleXMLElement $rpc, array &$state): string
    {
        $name = $rpc->getName();
        $inner = $rpc->children()[0] ?? null;
        $op = $inner?->getName() ?? '';

        if ($this->failRpc !== null && $op === $this->failRpc) {
            return $this->error('simulated failure');
        }

        switch ($op) {
            case 'lock-configuration':
                if ($this->lockedBy !== null) {
                    return $this->error('configuration database locked');
                }
                $this->lockedBy = $user;
                $this->events[] = "{$user}: lock";

                return '<ok/>';

            case 'unlock-configuration':
                $this->lockedBy = null;
                $this->events[] = "{$user}: unlock";

                return '<ok/>';

            case 'get-configuration':
                return $state['pending'] === []
                    ? '<configuration-information><configuration-output></configuration-output></configuration-information>'
                    : '<configuration-information><configuration-output>[edit]'."\n".'+ pending</configuration-output></configuration-information>';

            case 'load-configuration':
                if (($inner['rollback'] ?? null) !== null) {
                    $state['pending'] = [];

                    return '<ok/>';
                }

                foreach ($inner->xpath('.//*[local-name()="user"]') ?: [] as $u) {
                    $state['pending'][] = [
                        'name' => (string) $u->name,
                        'class' => isset($u->class) ? (string) $u->class : null,
                        'password' => isset($u->authentication) ? (string) $u->authentication->{'plain-text-password-value'} : null,
                        'delete' => (string) ($u['delete'] ?? '') === 'delete',
                    ];
                }

                return '<ok/>';

            case 'commit-configuration':
                $confirmed = isset($inner->confirmed);

                if ($confirmed) {
                    $this->beforeConfirmed = $this->users;
                    $this->confirmPending = true;
                } elseif ($this->confirmPending) {
                    $this->confirmPending = false;
                    $this->events[] = "{$user}: confirm";
                }

                foreach ($state['pending'] as $change) {
                    $this->apply($user, $change, $confirmed);
                }

                $state['pending'] = [];

                return '<ok/>';

            case 'get-software-information':
                return '<software-information><host-name>fake-ex</host-name></software-information>';

            case 'close-session':
                $this->events[] = "{$user}: close";

                return '<ok/>';
        }

        return $this->error("unknown rpc {$name}/{$op}");
    }

    /** @param  array<string, mixed>  $c */
    private function apply(string $user, array $c, bool $confirmed): void
    {
        $name = $c['name'];

        if ($c['delete']) {
            unset($this->users[$name]);
            $this->events[] = "{$user}: delete {$name}";

            return;
        }

        $sinif = $confirmed ? ' (confirmed)' : '';

        if (isset($this->users[$name])) {
            $this->users[$name]['password'] = $c['password'] ?? $this->users[$name]['password'];
            $this->events[] = "{$user}: chpass {$name}{$sinif}";

            return;
        }

        $this->users[$name] = ['class' => $c['class'], 'password' => (string) $c['password']];
        $this->events[] = "{$user}: create {$name} {$c['class']}";
    }

    private function error(string $message): string
    {
        return '<rpc-error><error-severity>error</error-severity><error-message>'.$message.'</error-message></rpc-error>';
    }
}
