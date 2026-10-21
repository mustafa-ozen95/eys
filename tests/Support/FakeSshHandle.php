<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SSH bağlantısının yerine geçen kukla.
 *
 * Sürücü oturumun tutamacını (handle) yalnızca beş metotla kullanır:
 * isConnected, enablePTY, exec, write, read. Gerçek bir makineye bağlanmadan sınanmak
 * istenen şey de zaten bu dördünün SIRASI — özellikle sudo yolunda parolanın
 * ve komut girdisinin ayrı aşamalarda yazılması.
 */
class FakeSshHandle
{
    /** Çalıştırılan komut. */
    public string $command = '';

    /** @var list<string> Çalıştırılan bütün komutlar, sırasıyla. */
    public array $commands = [];

    /** @var list<string> Yazma sırası: her write bir kayıt. */
    public array $writes = [];

    /** @var list<string> read() çağrılarının sırası (hangi desen sorulmuş). */
    public array $reads = [];

    public bool $ptyEnabled = false;

    /** Kopmuş bağlantıyı taklit etmek için: bkz. ConnectsOverSsh::execute. */
    public bool $connected = true;

    /**
     * sudo parola soruyor mu?
     *
     * Gerçek makinelerin ikisi de var: `wheel` üyesi bir hesap parola sorar,
     * NOPASSWD tanımlı bir hesap sormaz ve ön söz doğrudan biter. Sürücü
     * ikisini de doğru karşılamalı — parola soran makinede istemi beklemeli,
     * sormayanda beklemeden geçmeli.
     */
    public bool $sudoParolaSorar = true;

    public function __construct(private int $exitCode = 0) {}

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function enablePTY(): void
    {
        $this->ptyEnabled = true;
    }

    public function disablePTY(): void
    {
        $this->ptyEnabled = false;
    }

    public function exec(string $command): string
    {
        $this->command = $command;
        $this->commands[] = $command;

        return '';
    }

    public function write(string $data): void
    {
        $this->writes[] = $data;
    }

    /**
     * Beklenen deseni üretir: kapı (gate) ya da bitiş işareti.
     *
     * Gerçek sunucu gibi davranır — istenen işaret gelmiş gibi yanıt verir ki
     * sürücünün akışı ilerlesin ve sırayı sınayabilelim.
     */
    public function read(string $pattern, int $mode = 0): string
    {
        unset($mode);   /* Gerçek imzayla uyum için var; kukla kipe bakmaz. */

        $this->reads[] = $pattern;

        /*
         * "İSTEM Mİ, KAPI MI" okuması.
         *
         * Sürücü yükseltme parolasını istem GÖRÜLENE kadar yazmıyor (bkz.
         * ConnectsOverSsh: sudo parolayı okumadan önce girdi tamponunu
         * temizliyor). Kukla bunu taklit eder: parola soran makinede istem,
         * sormayanda doğrudan kapı döner.
         */
        if (str_contains($pattern, '|NDSGT') && preg_match('/NDSGT[0-9a-f]+/', $pattern, $m) === 1) {
            return $this->sudoParolaSorar
                ? "\nEYS-SUDO-PAROLA:"
                : "\n{$m[0]}\n";
        }

        if (preg_match('/NDSGT[0-9a-f]+/', $pattern, $m) === 1) {
            return "\n{$m[0]}\n";
        }

        if (preg_match('/NDSRC[0-9a-f]+/', $pattern, $m) === 1) {
            return "\n{$m[0]}:{$this->exitCode}\n";
        }

        return '';
    }
}
