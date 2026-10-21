<?php

declare(strict_types=1);

namespace App\Services\Rotation;

/**
 * PowerShell betiğine SIRRIN NASIL GİRECEĞİ — taşımaya göre değişir.
 *
 * Parola betiğin içine yazılmaz: betik `-EncodedCommand` ile komut satırında
 * gidiyor ve base64 gizlemek değildir — hedef makinenin süreç listesini gören
 * herkes çözebilir. Bu yüzden betikte bir YER TUTUCU durur, sırrı taşıma
 * getirir:
 *
 *  - SSH'ta standart girdi: `[Console]::In.ReadLine()`.
 *  - WinRM'de kabuğun ORTAM DEĞİŞKENİ.
 *
 * WinRM'de neden stdin değil: pywinrm 0.3.0'da `send_command_input`
 * YOK; yardımcı onu çağırsaydı parola isteyen her WinRM turu
 * "AttributeError: 'Protocol' object has no attribute 'send_command_input'"
 * diyerek düşerdi. WS-Man kabuğu açılırken ortam değişkeni
 * verilebiliyor ve o değişken komut satırında GÖRÜNMÜYOR — Plesk'in kendi
 * önerdiği yol da zaten bu (PSA_PASSWORD).
 */
final class PowerShellSecret
{
    /** Betikte sırrın yerini tutan işaret. */
    public const TOKEN = '{{EYS_GIZLI}}';

    /** WinRM kabuğunda sırrı taşıyan ortam değişkeni. */
    public const DEGISKEN = 'EYS_GIZLI';

    /** SSH: sır standart girdiden okunur. */
    public static function stdinIle(string $betik): string
    {
        return str_replace(self::TOKEN, '([Console]::In.ReadLine())', $betik);
    }

    /** WinRM: sır kabuğun ortam değişkeninde durur. */
    public static function ortamIle(string $betik): string
    {
        return str_replace(self::TOKEN, '$env:'.self::DEGISKEN, $betik);
    }

    /** PowerShell'in beklediği biçim: UTF-16LE, base64. */
    public static function kodla(string $betik): string
    {
        return base64_encode(mb_convert_encoding($betik, 'UTF-16LE', 'UTF-8'));
    }
}
