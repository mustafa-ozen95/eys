<?php

declare(strict_types=1);

namespace App\Services\Rotation\Junos\Transport;

use App\Services\Rotation\Junos\NetconfException;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * NETCONF'u SSH subsystem'i olarak açar (RFC 6242, bölüm 3).
 *
 * Bir örnek yalnızca bir oturum taşır: cihazda her oturum bir mgd süreci
 * fork'lar, açık bırakılmamalıdır.
 *
 * TAMPON BURADADIR, phpseclib'de değil. phpseclib'in read($ayırıcı) yöntemi
 * yalnızca "şu diziye kadar" okuyabiliyor; base:1.1 ise uzunluk önekine uymak
 * zorunda. İki okuma biçimi aynı baytları paylaştığı için tampon TEK olmalı;
 * bu yüzden phpseclib'den yalnızca "sıradaki kanal paketi" istenir (READ_NEXT)
 * ve birleştirme burada yapılır.
 *
 * phpseclib istisnaları BURADA NetconfException'a çevrilir, ve ÇEVRİLEN
 * istisna öncekini taşımaz: phpseclib'in izi bağlantıyı açan çağrının
 * bağımsız değişkenlerini (parola) içerebilir.
 */
final class SshSubsystemTransport implements NetconfTransport
{
    private ?SSH2 $ssh = null;

    private string $buffer = '';

    public function open(string $host, int $port, string $username, #[\SensitiveParameter] string $password, int $timeout): void
    {
        try {
            $ssh = new SSH2($host, $port, $timeout);
            $ssh->setTimeout($timeout);

            if (! $ssh->login($username, $password)) {
                throw NetconfException::transport("{$host} NETCONF kimlik doğrulaması başarısız.", 'auth')
                    ->withStage('connect');
            }

            if (! $ssh->startSubsystem('netconf')) {
                throw NetconfException::transport("{$host} üzerinde netconf subsystem'i açılamadı.", 'closed')
                    ->withStage('connect');
            }
        } catch (NetconfException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw NetconfException::transport(
                "{$host} NETCONF bağlantısı kurulamadı: {$exception->getMessage()}",
                'closed',
            )->withStage('connect');
        }

        $this->ssh = $ssh;
        $this->buffer = '';
    }

    public function write(string $data): void
    {
        $ssh = $this->connection();

        try {
            $ssh->write($data);
        } catch (Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    public function readUntil(string $terminator): string
    {
        while (($position = strpos($this->buffer, $terminator)) === false) {
            $this->fill();
        }

        return $this->take($position + strlen($terminator));
    }

    public function readExactly(int $bytes): string
    {
        if ($bytes < 0) {
            throw new NetconfException("Geçersiz okuma uzunluğu: {$bytes}");
        }

        while (strlen($this->buffer) < $bytes) {
            $this->fill();
        }

        return $this->take($bytes);
    }

    public function close(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
        $this->buffer = '';
    }

    private function take(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }

    /**
     * Tampona en az bir kanal paketi daha ekler.
     *
     * Kanal kapandığında phpseclib boş/true döndürür ve tampon bir daha
     * büyümez; beklemeye devam etmek sonsuz döngü olurdu.
     */
    private function fill(): void
    {
        $ssh = $this->connection();

        try {
            $packet = $ssh->read('', SSH2::READ_NEXT);
        } catch (Throwable $exception) {
            throw $this->translate($exception);
        }

        if (! is_string($packet) || $packet === '') {
            throw NetconfException::transport('NETCONF bağlantısı yanıt tamamlanmadan kapandı.', 'closed')
                ->withStage('read');
        }

        $this->buffer .= $packet;
    }

    private function translate(Throwable $exception): NetconfException
    {
        if ($this->ssh?->isTimeout() === true) {
            return NetconfException::transport('NETCONF yanıtı zaman aşımına uğradı.', 'timeout')
                ->withStage('read');
        }

        /*
         * Cihaz oturumu DÜŞÜRDÜYSE sonraki her istek SSH katmanında patlar ve
         * phpseclib'in iç dili operatörün önüne çıkardı (ör. geçersiz bir
         * öznitelik değeri oturumu anında kapatıyor). Söylenecek
         * şey iç hata değil, NE YAPILACAĞIDIR: oturum gitti, yenisi gerek.
         */
        foreach (['request pty', 'channel', 'Connection closed', 'not connected', 'disconnected'] as $isaret) {
            if (stripos($exception->getMessage(), $isaret) !== false) {
                return NetconfException::transport(
                    'Cihaz NETCONF oturumunu düşürdü; işlem yarıda kaldı. Yeni bir oturumla tekrar denenebilir.',
                    'closed',
                )->withStage('read');
            }
        }

        return NetconfException::transport("NETCONF bağlantısı koptu: {$exception->getMessage()}", 'closed')
            ->withStage('read');
    }

    private function connection(): SSH2
    {
        return $this->ssh ?? throw new NetconfException('NETCONF oturumu açık değil.');
    }
}
