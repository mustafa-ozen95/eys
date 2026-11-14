<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use App\Models\Credential;
use App\Services\Rotation\Drivers\Concerns\ConnectsOverSsh;
use App\Services\Rotation\RotationException;
use phpseclib3\Net\SSH2;

/**
 * Kaydın kendi SSH kimliğiyle makinede root betiği çalıştırır.
 *
 * Taşıma, parola döndürmenin taşımasıyla AYNI (ConnectsOverSsh): istem
 * görülmeden parola yazılmaz, çıkış kodu komutun kendisinden okunur, sudo
 * parolası komut satırına değil standart girdiye gider
 * (bkz. .ai/rules/remote-command.md).
 *
 * Çıktıdaki parola yankısı DÖNMEDEN silinir: PTY açıkken yazdığımız her şey
 * geri yankılanıyor.
 */
class OsUpdateShell
{
    use ConnectsOverSsh;

    /** Bağlantı kurma süresi; komut süresi ayrıca verilir. */
    private const BAGLANTI_SURESI = 20;

    protected function defaultPort(): int
    {
        return 22;
    }

    public function calistir(Credential $credential, string $betik, int $sure, string $hata): string
    {
        $kullanici = (string) $credential->username;
        $parola = (string) $credential->password;

        $this->timeout = self::BAGLANTI_SURESI;
        $oturum = $this->open($credential, $kullanici, $parola);

        try {
            /** @var SSH2 $ssh */
            $ssh = $oturum->handle;
            $ssh->setTimeout($sure);

            $cikti = $kullanici === 'root'
                ? $this->execute($oturum, 'sh -c '.escapeshellarg($betik), $hata)
                : $this->execute(
                    $oturum,
                    'sudo -n sh -c '.escapeshellarg($betik),
                    $hata,
                    '',
                    $this->sudoPrelude(),
                    $parola."\n",
                );
        } finally {
            $oturum->close();
        }

        $kucuk = strtolower($cikti);

        if (str_contains($kucuk, 'sorry, try again') || str_contains($kucuk, 'a password is required')) {
            throw new RotationException($hata.' Yükseltme (sudo) parolası kabul edilmedi.');
        }

        return $parola === '' ? $cikti : str_replace($parola, '***', $cikti);
    }
}
