<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\AccessType;
use App\Exceptions\RemoteSessionException;
use App\Models\Credential;

/**
 * Kasadaki kaydı konsol hedefine çevirir.
 *
 * Eksik bilgi burada yakalanır: oturum açma denemesi yapılmadan, kullanıcıya
 * neyin eksik olduğunu söyleyen bir hata verilir. Tarayıcıda boş bir terminal
 * açıp "bağlanamadı" demek, sebebini söylememek demekti.
 */
class TargetResolver
{
    /** Konsoldan açılabilen erişim türleri. */
    public const PROTOKOLLER = [AccessType::Ssh, AccessType::Rdp, AccessType::Vnc];

    public function resolve(Credential $credential): RemoteTarget
    {
        $protokol = $credential->access_type;

        if (! in_array($protokol, self::PROTOKOLLER, true)) {
            throw new RemoteSessionException(
                "{$credential->name} bir konsol kaydı değil; yalnızca SSH, uzak masaüstü ve VNC açılabilir.",
            );
        }

        if (blank($credential->host)) {
            throw new RemoteSessionException("{$credential->name} için adres tanımlı değil.");
        }

        /*
         * VNC'DE KULLANICI ADI YOKTUR.
         *
         * RFB'nin klasik kimlik doğrulaması yalnızca parola sorar; sunucu
         * kullanıcı adını hiç görmez. Kullanıcı adını burada da şart koşmak,
         * kullanıcıyı kasaya anlamsız bir değer yazmaya zorlardı — ve o değer
         * bir gün "bu hesapla bağlanıyoruz" diye okunurdu.
         */
        if (blank($credential->password)) {
            throw new RemoteSessionException("{$credential->name} için parola tanımlı değil.");
        }

        if ($protokol !== AccessType::Vnc && blank($credential->username)) {
            throw new RemoteSessionException("{$credential->name} için kullanıcı adı ve parola tanımlı değil.");
        }

        return new RemoteTarget(
            subject: $credential,
            label: $credential->name,
            host: (string) $credential->host,
            protocol: $protokol,
            port: $this->port($credential, $protokol),
            username: (string) $credential->username,
            password: (string) $credential->password,
        );
    }

    /** Kayıtta port yoksa protokolün alışılmış portu kullanılır. */
    private function port(Credential $credential, AccessType $protokol): int
    {
        return $credential->port ?? (int) config("remote.default_ports.{$protokol->value}");
    }
}
