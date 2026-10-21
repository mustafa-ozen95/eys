<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Support\Facades\Http;

/**
 * HPE iLO hesaplarını RIBCL (XML) ile yönetir — iLO 3 ve sonrası.
 *
 * NEDEN REDFISH DEĞİL: Redfish iLO 3'te YOKTUR, iLO 4'te ancak 2.30 ve
 * sonrası firmware'de gelir. RIBCL iLO 3, 4, 5 ve 6'da çalışır; parkta karışık
 * nesil varsa ortak payda budur. Yeni nesillerde Redfish tercih edilir
 * (bkz. RedfishPasswordDriver); kayıttaki sürücü alanı hangisinin
 * kullanılacağını söyler.
 *
 * ÖLÇÜLDÜ (HPE iLO Scripting and Command Line Guide):
 *
 *   Parola değiştirme:
 *     <RIBCL VERSION="2.0"><LOGIN USER_LOGIN="..." PASSWORD="...">
 *       <USER_INFO MODE="write">
 *         <MOD_USER USER_LOGIN="username"><PASSWORD value="newpassword"/></MOD_USER>
 *       </USER_INFO></LOGIN></RIBCL>
 *
 *   Hesap açma:  <ADD_USER USER_NAME="..." USER_LOGIN="..." PASSWORD="...">
 *                  <ADMIN_PRIV value="Y"/> ... </ADD_USER>
 *   Hesap silme: <DELETE_USER USER_LOGIN="username"/>
 *   Yanıt:       <RESPONSE STATUS="0x0000" MESSAGE='No Error' />
 *
 * UÇ ADRESİ: `POST /ribcl` (HTTPS, 443). Bu HPE'nin 2010 tarihli scripting
 * kılavuzunda geçmiyor — o kılavuz Perl/cpqlocfg yolunu anlatıyor. Adres,
 * olgun bir istemcinin (python-hpilo) gerçek davranışından doğrulandı:
 * `POST /ribcl HTTP/1.1`. iLO 2 ve öncesi RIBCL'i ham soket üzerinden
 * konuşuyor; kapsamımız iLO 3 ve sonrası olduğu için HTTP yolu yeterli.
 *
 * YANIT TEK BİR XML BELGESİ DEĞİLDİR: iLO her komut bloğu için ayrı bir
 * <RIBCL> parçası döndürür ve bunlar arka arkaya gelir. Tek kök bekleyen bir
 * ayrıştırıcı bu yüzden patlar; durum satırları düzenli ifadeyle okunur.
 */
class RibclPasswordDriver implements PasswordDriver
{
    public const KEY = 'ribcl';

    private const ENDPOINT = '/ribcl';

    private const SUCCESS = '0x0000';

    public function __construct(private int $timeout = 30) {}

    public function supportsRecovery(): bool
    {
        return true;
    }

    public function canVerify(): bool
    {
        return true;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) && blank($credential->url) => 'Adres tanımlı değil.',
            blank($credential->username) => 'Kullanıcı adı tanımlı değil.',
            blank($credential->password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            default => null,
        };
    }

    /**
     * Kimliği zararsız bir okuma komutuyla sınar.
     *
     * GET_FW_VERSION hiçbir şeyi değiştirmez ve yönetici yetkisi istemez;
     * amaç yalnızca "bu kimlikle giriliyor mu" sorusunu yanıtlamak.
     */
    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $this->send($credential, $username, $password,
            '<RIB_INFO MODE="read"><GET_FW_VERSION/></RIB_INFO>');

        return new RotationSession($credential, $username, $password, static fn () => null);
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->guardName($username);

        /*
         * Bütün ayrıcalıklar verilir: yetkisiz bir iLO hesabı parola
         * değiştiremez ve kurtarma hesabı işe yaramazdı.
         */
        $this->write($session, sprintf(
            '<ADD_USER USER_NAME="%s" USER_LOGIN="%s" PASSWORD="%s">'
            .'<ADMIN_PRIV value="Y"/><REMOTE_CONS_PRIV value="Y"/><RESET_SERVER_PRIV value="Y"/>'
            .'<VIRTUAL_MEDIA_PRIV value="Y"/><CONFIG_ILO_PRIV value="Y"/></ADD_USER>',
            $this->escape($username),
            $this->escape($username),
            $this->escape($password),
        ));
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $this->write($session, sprintf(
            '<MOD_USER USER_LOGIN="%s"><PASSWORD value="%s"/></MOD_USER>',
            $this->escape($username),
            $this->escape($newPassword),
        ));
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);

        $this->write($session, sprintf(
            '<DELETE_USER USER_LOGIN="%s"/>',
            $this->escape($username),
        ));
    }

    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $this->open($credential, $username, $password)->close();

            return true;
        } catch (RotationException) {
            return false;
        }
    }

    private function write(RotationSession $session, string $body): void
    {
        /** @var Credential $credential */
        $credential = $session->handle;

        $this->send(
            $credential,
            $session->username,
            $session->password,
            '<USER_INFO MODE="write">'.$body.'</USER_INFO>',
        );
    }

    private function send(Credential $credential, string $username, string $password, string $body): void
    {
        $script = sprintf(
            '<?xml version="1.0"?>'."\n"
            .'<RIBCL VERSION="2.0"><LOGIN USER_LOGIN="%s" PASSWORD="%s">%s</LOGIN></RIBCL>',
            $this->escape($username),
            $this->escape($password),
            $body,
        );

        $response = Http::withHeaders(['Content-Type' => 'text/xml'])
            ->withOptions(['verify' => false])
            ->timeout($this->timeout)
            ->withBody($script, 'text/xml')
            ->post(DeviceAddress::for($credential).self::ENDPOINT);

        if ($response->failed()) {
            throw new RotationException('iLO isteği başarısız ('.$response->status().').');
        }

        $this->guardStatuses($response->body());
    }

    /**
     * Bütün RESPONSE satırları 0x0000 olmalı.
     *
     * iLO bir komut başarısız olsa da HTTP 200 döndürür; yalnızca duruma
     * bakmak başarısız bir parola değişimini başarılı saymak olurdu.
     * Ayrıca HİÇ durum satırı yoksa da hata verilir — sessiz geçmek,
     * hiçbir şey yapılmadığını başarı sanmaktır.
     */
    private function guardStatuses(string $body): void
    {
        preg_match_all(
            '/<RESPONSE\s+STATUS\s*=\s*"([^"]+)"(?:\s+MESSAGE\s*=\s*[\'"]([^\'"]*)[\'"])?/i',
            $body,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matches === []) {
            throw new RotationException('iLO yanıtında durum satırı yok; komut işlenmemiş olabilir.');
        }

        foreach ($matches as $match) {
            if (strtolower($match[1]) === self::SUCCESS) {
                continue;
            }

            $message = trim($match[2] ?? '') ?: 'bilinmeyen hata';

            throw new RotationException("iLO reddetti ({$match[1]}): {$message}");
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function guardName(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,39}$/', $username) !== 1) {
            throw new RotationException('Hesap adı iLO için geçersiz karakter içeriyor.');
        }
    }
}
