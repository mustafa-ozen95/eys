<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HPE iLO hesaplarını Redfish ile yönetir.
 *
 * ÖLÇÜLDÜ (HPE'nin kendi iLO REST API belgeleri, ilo-rest-api-docs):
 *  - Kimlik: HTTP Basic — "The RESTful API allows you to use HTTP Basic
 *    Authentication using a valid iLO user name and password."
 *  - Hesap oluştur: POST /redfish/v1/AccountService/Accounts/
 *      {"UserName","Password"} + yetki alanı. Yetkinin adı nesle göre değişir
 *      (iLO 5'te RoleId, iLO 4'te Oem.Hp.Privileges); bkz. privileges().
 *  - Parola değiştir: PATCH /redfish/v1/AccountService/Accounts/{id}/
 *      {"Password":"..."}   — GET'te Password hep null döner, PATCH edilir.
 *  - Hesap sil: DELETE /redfish/v1/AccountService/Accounts/{id}/
 *
 * KİMLİK NUMARASI KULLANICI ADI DEĞİL: hesaplar "14" gibi sayısal Id ile
 * adreslenir. Kullanıcı adından kimliğe geçmek için koleksiyon taranır;
 * doğrudan kullanıcı adıyla PATCH etmek 404 verirdi.
 *
 * ETag: iLO her kaynakta @odata.etag döndürüyor. PATCH ve DELETE isteklerine
 * If-Match olarak geri konur — aynı anda başka bir yerden değiştirilmiş bir
 * hesabın üzerine körlemesine yazmayalım.
 *
 * Sertifika doğrulaması kapalı: iLO sertifikaları neredeyse her zaman
 * kendinden imzalıdır. Yönetim ağında çalışıyoruz; alternatif sürücünün hiç
 * çalışmaması olurdu.
 */
class RedfishPasswordDriver implements PasswordDriver
{
    public const KEY = 'redfish';

    private const ACCOUNTS = '/redfish/v1/AccountService/Accounts/';

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
     * Basic kimlik doğrulama; ayrı bir oturum açılmaz.
     *
     * iLO'nun oturum servisi de var ama oturum ömrü yönetmek bu iş için
     * fazladan durum demek: yaptığımız üç dört çağrı bağımsız ve kısa.
     * Kimliğin GEÇERLİ olduğu burada bir kez sınanır.
     */
    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $response = $this->client($credential, $username, $password)->get(self::ACCOUNTS);

        if ($response->failed()) {
            throw new RotationException('iLO kimliği kabul edilmedi ('.$response->status().').');
        }

        return new RotationSession($credential, $username, $password, static fn () => null);
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->guardName($username);

        $credential = $this->credential($session);

        $response = $this->client($credential, $session->username, $session->password)
            ->post(self::ACCOUNTS, [
                'UserName' => $username,
                'Password' => $password,
                ...$this->privileges($credential, $session, $username),
            ]);

        $this->guardResponse($response, 'Kurtarma hesabı açılamadı');
    }

    /**
     * Kurtarma hesabının yetkisi — alan adı NESLE GÖRE değişir.
     *
     * Yetkisiz bir iLO hesabı giriş bile yapamaz; yetki verilmezse kurtarma
     * hesabı daha kanıt aşamasında düşer. Ama yetkinin YAZILDIĞI ALAN nesilden
     * nesle aynı değil:
     *
     *  - iLO 5 (1.40+): "RoleId": "Administrator".
     *  - iLO 4: RoleId YOKTUR (fw 2.78 ve 2.81'de denendi) — hesap
     *    belgesinde RoleId hiç geçmiyor, yetkiler Oem.Hp.Privileges altında
     *    duruyor ve RoleId göndermek isteği "Base.0.10.PropertyUnknown" ile
     *    reddediyor.
     *
     * Nesil TAHMİN EDİLMEZ: cihazın kendi hesap belgesine bakılıp aynı biçimde
     * yazılır. Firmware sürümüne bakmak da olurdu ama sürüm dizesini ayrıştırıp
     * eşik karşılaştırmak, cihazın zaten açıkça söylediği şeyi dolambaçlı
     * tahmin etmek olurdu.
     *
     * @return array<string, mixed>
     */
    private function privileges(Credential $credential, RotationSession $session, string $username): array
    {
        $sample = $this->anyAccount($credential, $session);

        if (array_key_exists('RoleId', $sample)) {
            return ['RoleId' => 'Administrator'];
        }

        /* iLO 4'te "Hp", iLO 5'te "Hpe" — anahtar cihazdan okunur. */
        $oem = array_key_first((array) ($sample['Oem'] ?? []));

        if ($oem === null) {
            throw new RotationException('iLO hesap biçimi tanınmadı: yetki alanı bulunamadı.');
        }

        return ['Oem' => [$oem => [
            'LoginName' => $username,

            /*
             * Tam yetki bilinçli: kurtarma hesabının işi, ana parola
             * değişirken kapı kilitlenirse içeri girip düzeltmek. Eksik
             * yetkili bir hesap tam da ihtiyaç duyulan anda yetersiz kalırdı.
             */
            'Privileges' => [
                'LoginPriv' => true,
                'RemoteConsolePriv' => true,
                'UserConfigPriv' => true,
                'VirtualMediaPriv' => true,
                'VirtualPowerAndResetPriv' => true,
                'iLOConfigPriv' => true,
            ],
        ]]];
    }

    /**
     * Koleksiyondaki ilk hesabın belgesi — biçim örneği olarak.
     *
     * @return array<string, mixed>
     */
    private function anyAccount(Credential $credential, RotationSession $session): array
    {
        $client = $this->client($credential, $session->username, $session->password);

        $collection = $client->get(self::ACCOUNTS);

        $this->guardResponse($collection, 'iLO hesap listesi okunamadı');

        foreach ((array) $collection->json('Members', []) as $member) {
            $uri = $member['@odata.id'] ?? null;

            if (! is_string($uri)) {
                continue;
            }

            $account = $client->get($uri);

            if ($account->successful()) {
                return (array) $account->json();
            }
        }

        throw new RotationException('iLO hesap listesi boş döndü.');
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $credential = $this->credential($session);
        [$id, $etag] = $this->findAccount($credential, $session, $username);

        $response = $this->client($credential, $session->username, $session->password)
            ->withHeaders($etag === null ? [] : ['If-Match' => $etag])
            ->patch(self::ACCOUNTS.$id.'/', ['Password' => $newPassword]);

        $this->guardResponse($response, 'Parola değiştirilemedi');
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);

        $credential = $this->credential($session);
        [$id, $etag] = $this->findAccount($credential, $session, $username);

        $response = $this->client($credential, $session->username, $session->password)
            ->withHeaders($etag === null ? [] : ['If-Match' => $etag])
            ->delete(self::ACCOUNTS.$id.'/');

        $this->guardResponse($response, 'Kurtarma hesabı silinemedi');
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

    /**
     * Kullanıcı adından hesap kimliğine ve ETag'ine geçer.
     *
     * @return array{0: string, 1: ?string}
     */
    private function findAccount(Credential $credential, RotationSession $session, string $username): array
    {
        $client = $this->client($credential, $session->username, $session->password);

        $collection = $client->get(self::ACCOUNTS);

        $this->guardResponse($collection, 'iLO hesap listesi okunamadı');

        foreach ((array) $collection->json('Members', []) as $member) {
            $uri = $member['@odata.id'] ?? null;

            if (! is_string($uri)) {
                continue;
            }

            $account = $client->get($uri);

            if ($account->failed() || $account->json('UserName') !== $username) {
                continue;
            }

            /*
             * ETag dizi ERİŞİMİYLE okunur, json('@odata.etag') ile DEĞİL:
             * anahtar nokta içeriyor ve json() nokta gösterimini iç içe yol
             * sanıp null döndürüyor. Fark edilmeden ETag hiç gönderilmiyor,
             * eşzamanlı değişiklik koruması sessizce kapalı kalıyordu.
             */
            $body = (array) $account->json();

            return [(string) ($body['Id'] ?? ''), $body['@odata.etag'] ?? null];
        }

        throw new RotationException("iLO'da '{$username}' adlı hesap bulunamadı.");
    }

    private function credential(RotationSession $session): Credential
    {
        return $session->handle;
    }

    private function client(Credential $credential, string $username, string $password): PendingRequest
    {
        return Http::withBasicAuth($username, $password)
            ->withHeaders(['OData-Version' => '4.0'])
            ->acceptJson()
            ->asJson()
            ->withOptions(['verify' => false])
            ->timeout($this->timeout)
            ->baseUrl(DeviceAddress::for($credential));
    }

    /**
     * iLO hataları gövdede ayrıntılı gelir; yalnızca durum koduna bakmak
     * "neden olmadı" bilgisini kaybettirirdi.
     */
    private function guardResponse($response, string $failure): void
    {
        if ($response->successful()) {
            return;
        }

        /*
         * Anahtarlar NOKTA içeriyor ('@Message.ExtendedInfo'); data_get
         * nokta gösterimini iç içe yol sanıp null döndürür. Dizi erişimiyle
         * okunur, yoksa iLO'nun anlattığı neden kaybolur ve ekranda yalnızca
         * "400" yazardı.
         */
        $body = (array) $response->json();
        $extended = $body['error']['@Message.ExtendedInfo'][0]['MessageId'] ?? null;

        $detail = $extended
            ?? ($body['error']['message'] ?? null)
            ?? $response->status();

        throw new RotationException($failure.': '.$detail);
    }

    /** iLO kullanıcı adı en fazla 39 karakter; adres yoluna girmez ama gövdeye girer. */
    private function guardName(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,39}$/', $username) !== 1) {
            throw new RotationException('Hesap adı iLO için geçersiz karakter içeriyor.');
        }
    }
}
