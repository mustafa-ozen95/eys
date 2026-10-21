<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\Rotation\Contracts\LimitsPasswordLength;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use App\Services\Rotation\Sso\SsoAdminClient;
use Illuminate\Support\Facades\Http;

/**
 * vCenter SSO hesaplarını (vmdir) döndürür — administrator@vsphere.local gibi.
 *
 * VCenterPasswordDriver'DAN AYRI olmasının nedeni, kapsamın gerçekten ayrı
 * olması: o sürücü appliance'ın işletim sistemi hesaplarını yönetir ve o
 * uçlar SSO kullanıcılarına DOKUNMAZ. Aynı makinede iki ayrı kimlik dünyası
 * var; tek sürücüde birleştirmek "hangi hesap" sorusunu kodun içine gizlerdi.
 *
 * SSH KULLANILMAZ. Appliance kabuğu `appliancesh`; rastgele komut çalıştırmaz
 * ve SSH kapalı olabilir. Bütün iş, cihazın kendi SOAP arayüzünden yapılır
 * (bkz. SsoAdminClient).
 *
 * DOĞRULAMA parola değişikliğinin GERÇEKTEN işlediğini kanıtlar: yeni kimlikle
 * REST oturumu açılır. SOAP'ın "tamam" demesi yetmez.
 *
 * KURTARMA HESABI, panelin standart sırasıdır: önce tam yetkili geçici bir
 * SSO kullanıcısı açılır ve onunla giriş sınanır; ana parola ancak ondan
 * sonra değişir. Bir aksilikte içeri girecek ikinci bir kapı kalır.
 */
class VCenterSsoPasswordDriver implements LimitsPasswordLength, PasswordDriver
{
    public const KEY = 'vcenter_sso';

    public function __construct(private int $timeout = 30) {}

    public function supportsRecovery(): bool
    {
        return true;
    }

    public function canVerify(): bool
    {
        return true;
    }

    /**
     * vCenter 8.0.3'te (GetLocalPasswordPolicy) parola
     * 8-20 karakter olmalı. Panelin varsayılanı daha uzunsa kısaltılır.
     */
    public function maxPasswordLength(): int
    {
        return (int) config('rotation.vcenter.sso_max_password', 20);
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) && blank($credential->url) => 'Adres tanımlı değil.',
            blank($credential->username) => 'Kullanıcı adı tanımlı değil.',
            blank($credential->password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            ! str_contains((string) $credential->username, '@') => 'SSO kullanıcısı alan adıyla yazılmalı (ör. administrator@vsphere.local).',
            default => null,
        };
    }

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $client = $this->client($credential);
        $assertion = $client->token($this->principal($credential, $username), $password);

        return new RotationSession(
            ['credential' => $credential, 'assertion' => $assertion],
            $username,
            $password,
            static fn () => null,
        );
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->guardName($username);

        [$credential, $assertion] = $this->context($session);
        $client = $this->client($credential);

        $client->createUser($assertion, $username, $password);

        /*
         * Yetkisiz bir SSO hesabı hiçbir şey yapamaz; kurtarma hesabı kanıt
         * aşamasında düşerdi. Yetki gruptan gelir.
         */
        $client->addToGroup($assertion, $username, config('rotation.vcenter.sso_group', 'Administrators'));
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        [$credential, $assertion] = $this->context($session);

        $this->client($credential)->resetPassword($assertion, $this->localName($username), $newPassword);
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);

        [$credential, $assertion] = $this->context($session);

        $this->client($credential)->deleteUser($assertion, $this->localName($username));
    }

    /**
     * Kimlik gerçekten giriyor mu.
     *
     * REST oturumu denenir: SOAP'ın kabul etmesi parolanın ÇALIŞTIĞINI
     * göstermez, yalnızca yazıldığını gösterir.
     */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $response = Http::withOptions(['verify' => false])
                ->withBasicAuth($this->principal($credential, $username), $password)
                ->timeout($this->timeout)
                ->post(DeviceAddress::port($credential, (int) config('rotation.vcenter.api_port', 443)).'/api/session');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{0: Credential, 1: string} */
    private function context(RotationSession $session): array
    {
        $handle = $session->handle;

        if (! is_array($handle) || ! $handle['credential'] instanceof Credential) {
            throw new RotationException('vCenter SSO oturumu tanınmadı.');
        }

        return [$handle['credential'], (string) $handle['assertion']];
    }

    private function client(Credential $credential): SsoAdminClient
    {
        return new SsoAdminClient(
            DeviceAddress::port($credential, (int) config('rotation.vcenter.api_port', 443)),
            $this->domain($credential),
            $this->timeout,
        );
    }

    /** Kaydın kullanıcı adındaki alan: administrator@vsphere.local -> vsphere.local */
    private function domain(Credential $credential): string
    {
        $parts = explode('@', (string) $credential->username);

        return end($parts);
    }

    /** Girişte alan adı ŞART; kurtarma hesabı ise adıyla geliyor. */
    private function principal(Credential $credential, string $username): string
    {
        return str_contains($username, '@') ? $username : $username.'@'.$this->domain($credential);
    }

    /** SOAP tarafı alansız ad ister. */
    private function localName(string $username): string
    {
        return explode('@', $username)[0];
    }

    private function guardName(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $this->localName($username)) !== 1) {
            throw new RotationException('Hesap adı vCenter SSO için geçersiz karakter içeriyor.');
        }
    }
}
