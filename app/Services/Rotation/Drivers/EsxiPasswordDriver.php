<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use App\Services\Rotation\VSphere\VSphereClient;

/**
 * ESXi yerel hesaplarını vSphere Web Services (SOAP) ile yönetir.
 *
 * ÖLÇÜLDÜ (VMware Web Services SDK belgeleri):
 *  - HostLocalAccountManager: CreateUser, UpdateUser, RemoveUser
 *  - CreateUser/UpdateUser bir HostAccountSpec alır (id, password, description)
 *  - Yeni hesap AuthorizationManager ile yetkilendirilir; Administrator rolü -1
 *
 * Nesne kimlikleri (ha-localacctmgr gibi) SABİT YAZILMADI: sunucu onları
 * RetrieveServiceContent ile kendisi bildiriyor. Sabit yazmak, sürüm ya da
 * ürün değiştiğinde sessizce yanlış nesneye çağrı yapmak demekti.
 *
 * KURTARMA HESABI AÇAR: ESXi'de parolasını değiştirdiğimiz hesapla
 * bağlanıyoruz, tıpkı SSH'de olduğu gibi. Bu yüzden altı aşamalı sıra geçerli.
 *
 * ESXi 8.0.3'te kuru provayla doğrulandı (root parolasına dokunulmadan
 * geçici hesap açıldı, girildi, silindi):
 *  - RemoveUser `<userName>` alır; belgelerin "üçü de HostAccountSpec alır"
 *    ifadesi yanıltıcı, WSDL doğruymuş.
 *  - Administrator rolü (roleId -1, propagate) yeni hesabın SOAP ile giriş
 *    yapması için YETİYOR; hesap kendi oturumuyla kendini silebiliyor.
 *  - 24 karakterlik üretilmiş parolayı ESXi parola politikası kabul ediyor.
 */
class EsxiPasswordDriver implements PasswordDriver
{
    public const KEY = 'vsphere';

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

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $client = $this->client($credential);
        $client->login($username, $password);

        return new RotationSession($client, $username, $password, static fn () => $client->logout());
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->guardName($username);

        /** @var VSphereClient $client */
        $client = $session->handle;

        $client->createUser($username, $password, 'EYS gecici kurtarma hesabi');

        /*
         * Hesap açmak yetmiyor: yetkisiz bir ESXi hesabı oturum bile açamaz.
         * Yetki verilmezse kurtarma hesabı kanıt aşamasında düşer ve tur
         * boşuna iptal olurdu.
         */
        $client->grantAdmin($username);
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        /** @var VSphereClient $client */
        $client = $session->handle;

        $client->updateUser($username, $newPassword);
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);

        /** @var VSphereClient $client */
        $client = $session->handle;

        $client->removeUser($username);
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

    private function client(Credential $credential): VSphereClient
    {
        return new VSphereClient(DeviceAddress::for($credential), $this->timeout);
    }

    /** Hesap adı SOAP gövdesine giriyor; kaçış yapılıyor ama biçim de sınırlı. */
    private function guardName(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $username) !== 1) {
            throw new RotationException('Hesap adı ESXi için geçersiz karakter içeriyor.');
        }
    }
}
