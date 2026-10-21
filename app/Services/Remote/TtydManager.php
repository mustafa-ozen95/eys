<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Exceptions\RemoteSessionException;

/**
 * Tarayıcı içi SSH terminali (ttyd) süreçlerini yönetir.
 *
 * Her oturum kendi portunda ayrı bir ttyd süreci olarak çalışır; nginx bu
 * portu /ssh-proxy/<port>/ altından yayımlar. Port aralığı config'te tanımlıdır
 * ve nginx kısıtıyla birebir eşleşmek zorundadır.
 *
 * Parola komut satırına yazılmaz: sshpass'e SSHPASS ortam değişkeniyle geçilir,
 * böylece süreç listesinde (ps) görünmez.
 */
class TtydManager
{
    /**
     * SIGTERM'in sayısal karşılığı.
     *
     * Sinyal SABİTLERİ pcntl uzantısından gelir ve pcntl yalnızca CLI'da
     * yüklü; FPM'de yok. `posix_kill($pid, SIGTERM)` web isteğinde
     * "Undefined constant" verir ve oturumu kapatma düşer — süreç ayakta,
     * port da rezerve kalır. Sayı POSIX'te sabittir.
     */
    private const SIGTERM = 15;

    public function __construct(private RemoteSessionStore $sessions) {}

    /**
     * @return array{port: int, path: string}
     */
    public function start(RemoteTarget $target, string $token): array
    {
        $port = $this->reservePort($token);
        $directory = storage_path('app/'.config('remote.ttyd.session_path'));

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RemoteSessionException('Oturum dizini oluşturulamadı.');
        }

        $process = proc_open(
            $this->command($target, $port),
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', "{$directory}/{$token}.log", 'a'],
                2 => ['file', "{$directory}/{$token}.log", 'a'],
            ],
            $pipes,
            $directory,
            ['SSHPASS' => $target->password],
        );

        // Oturum günlüğü yalnızca uygulama kullanıcısına açık olsun.
        @chmod("{$directory}/{$token}.log", 0600);

        if (! is_resource($process)) {
            $this->sessions->releasePort($port);

            throw new RemoteSessionException('Terminal süreci başlatılamadı.');
        }

        return [
            'port' => $port,
            'path' => rtrim((string) config('remote.ttyd.proxy_path'), '/')."/{$port}/",
        ];
    }

    public function stop(int $port): void
    {
        foreach ($this->processIds($port) as $pid) {
            posix_kill($pid, self::SIGTERM);
        }

        $this->sessions->releasePort($port);
    }

    /**
     * @return array<int, string>
     */
    private function command(RemoteTarget $target, int $port): array
    {
        return [
            (string) config('remote.ttyd.binary'),
            '--port', (string) $port,
            '--interface', '127.0.0.1',
            '--once',
            '--writable',
            (string) config('remote.ttyd.sshpass_binary'), '-e',
            (string) config('remote.ttyd.ssh_binary'),
            '-p', (string) $target->port,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',

            /*
             * KULLANICI ADI "-l" İLE, konağa yapıştırılarak DEĞİL.
             *
             * Tek argüman verilirse ("kullanici@konak") ssh o argümanı, tire
             * ile başlıyorsa, bir SEÇENEK olarak ayrıştırır ("-oConnectTimeout=..."
             * biçimindeki bir kullanıcı adı "invalid time value" hatası verir,
             * yani seçenek olarak okunur) ve bağlantı anlaşılmaz bir hatayla
             * düşer.
             *
             * "-l" değerini bir sonraki argümandan alır ve tire ile başlasa
             * bile seçenek saymaz. Konak en sonda, kendi argümanında durur.
             */
            '-l', $target->username,
            $target->host,
        ];
    }

    private function reservePort(string $token): int
    {
        [$from, $to] = config('remote.ttyd.port_range');

        for ($port = $from; $port <= $to; $port++) {
            if ($this->sessions->reservePort($port, $token) && ! $this->isListening($port)) {
                return $port;
            }
        }

        throw new RemoteSessionException('Boş terminal portu kalmadı.');
    }

    private function isListening(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @return array<int, int>
     */
    private function processIds(int $port): array
    {
        exec('pgrep -f '.escapeshellarg("ttyd.*--port {$port}"), $output, $status);

        return $status === 0 ? array_map(intval(...), $output) : [];
    }
}
