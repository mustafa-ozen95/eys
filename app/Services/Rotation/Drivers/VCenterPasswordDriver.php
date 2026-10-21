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
 * vCenter Server Appliance YEREL hesaplarını REST API ile yönetir.
 *
 * KAPSAM: appliance'ın işletim sistemi hesapları — `root` ve 5480 arayüzünden
 * yönetilenler. SSO KULLANICILARI (administrator@vsphere.local gibi) BU
 * SÜRÜCÜNÜN KAPSAMINDA DEĞİLDİR; onlar vmdir'de durur ve bu uçlar onlara
 * dokunmaz. vSphere 8.0 belgesi de appliance kabuk komutlarının SSO
 * kullanıcılarını kapsamadığını açıkça söylüyor.
 *
 * ÖLÇÜLDÜ (Broadcom'un resmi vSphere Automation SDK'sı, vmware-vcenter
 * paketi — spec'ten üretiliyor, com/vmware/appliance_client.py):
 *
 *   Oturum : POST   /api/session                              (HTTP Basic)
 *            -> gövde bir jeton; sonraki isteklerde
 *               `vmware-api-session-id` başlığında taşınır
 *   Oku    : GET    /api/appliance/local-accounts/{username}
 *   Aç     : POST   /api/appliance/local-accounts/{username}   (Config)
 *   Değiştir: PATCH /api/appliance/local-accounts/{username}   (UpdateConfig)
 *   Sil    : DELETE /api/appliance/local-accounts/{username}
 *
 * `UpdateConfig` alanları arasında `password` ve `old_password` var.
 * SDK'nın kendi açıklaması şunu söylüyor: "old_password ... required in case
 * of the password change, not required if superAdmin user changes the
 * password of the other user."
 *
 * BU CÜMLE SIRAYI BELİRLİYOR: kurtarma hesabıyla bağlanıp ASIL hesabın
 * parolasını değiştirdiğimizde eski parola İSTENMEZ. Kendi parolasını
 * değiştiren bir oturumda ise istenir; repair() o yola düştüğünde eski
 * parolayı gönderiyoruz.
 *
 * ROL ADI TAHMİN EDİLMEZ: kurtarma hesabı, asıl hesabın rolleri OKUNUP aynısı
 * verilerek açılır. "superAdmin" gibi bir dizgiyi koda gömmek, kurulumdan
 * kuruluma değişebilecek bir kimliğe bel bağlamak olurdu.
 */
class VCenterPasswordDriver implements PasswordDriver
{
    public const KEY = 'vcenter';

    private const ACCOUNTS = '/api/appliance/local-accounts/';

    /* Hesap AÇMA yalnızca burada var; bkz. createRecovery. */
    private const LEGACY_ACCOUNTS = '/rest/appliance/local-accounts/';

    public function __construct(private int $timeout = 30) {}

    /**
     * Kurtarma hesabı AÇILMAZ.
     *
     * vCenter 8.0.3'te yerel hesap uçlarını hiçbir YEREL
     * hesap kullanamıyor — appliance'ın kendi root'u bile 403 "Unable to
     * authorize user" alıyor. Yetki SSO tarafından geliyor.
     *
     * Yani açılacak geçici yerel hesap parolayı değiştiremezdi: tur, işe
     * yaramayan bir hesap açıp yarıda kalırdı. Zaten gerek de yok —
     * değiştiren kimlik (SSO) ile değişen parola (root) ayrı şeyler, kapı
     * hiçbir aşamada kapanmıyor.
     */
    public function supportsRecovery(): bool
    {
        return false;
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
            str_contains((string) $credential->username, '@') => 'Bu bir SSO hesabı görünüyor; bu sürücü yalnızca appliance yerel hesaplarını yönetir.',

            /*
             * Değiştiren kimlik SSO OLMAK ZORUNDA (bkz. supportsRecovery).
             * Boş bırakılırsa kaydın kendi hesabı (root) kullanılır ve tur
             * ilk adımda 403 alırdı; bunu ekranda önceden söylemek gerekir.
             */
            blank($credential->rotation_username) => 'Güncelleme hesabı boş: appliance yerel hesaplarını yalnızca SSO yöneticisi yönetebilir (ör. administrator@vsphere.local).',
            ! str_contains((string) $credential->rotation_username, '@') => 'Güncelleme hesabı SSO yöneticisi olmalı (ör. administrator@vsphere.local).',
            default => null,
        };
    }

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $response = Http::withBasicAuth($username, $password)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->timeout($this->timeout)
            ->post($this->apiUrl($credential).'/api/session');

        if ($response->failed()) {
            throw new RotationException("vCenter kimliği kabul edilmedi ({$response->status()}).");
        }

        /* Yanıt gövdesi jetonun kendisidir; JSON dizgi olarak gelir. */
        $token = is_string($response->json()) ? $response->json() : trim($response->body(), "\"\n\r");

        if ($token === '') {
            throw new RotationException('vCenter oturum jetonu okunamadı.');
        }

        $this->guardAuthorization($credential, $token);

        return new RotationSession(
            ['credential' => $credential, 'token' => $token],
            $username,
            $password,
            fn () => $this->closeSession($credential, $token),
        );
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->guardName($username);

        $credential = $this->credential($session);

        /*
         * Roller HEDEF HESAPTAN kopyalanır. Yetkisiz bir hesap parolayı
         * değiştiremez; rol adını koda gömmek yerine var olandan almak hem
         * doğru hem de kuruluma bağımsız.
         */
        $target = $this->client($session)->get(self::ACCOUNTS.rawurlencode((string) $credential->username));

        $this->guardResponse($target, 'Hedef hesabın rolleri okunamadı');

        $roles = (array) ($target->json()['roles'] ?? []);

        if ($roles === []) {
            throw new RotationException('Hedef hesabın rolü okunamadı; kurtarma hesabı yetkisiz kalırdı.');
        }

        /*
         * Hesap açma ESKİ ad alanındadır. vCenter 8.0.3'te
         * POST /api/appliance/local-accounts/{ad} 404 döndürüyor, aynı istek
         * /rest altında 200 veriyor. Yeni ad alanı bu işlemi hiç sunmuyor;
         * okuma, değiştirme ve silme ise /api'de.
         */
        $response = $this->client($session)->post(self::LEGACY_ACCOUNTS.rawurlencode($username), [
            'config' => [
                'password' => $password,
                'roles' => array_values($roles),
                'full_name' => 'EYS gecici kurtarma hesabi',
                'enabled' => true,
            ],
        ]);

        $this->guardResponse($response, 'Kurtarma hesabı açılamadı');
    }

    /**
     * Parolayı değiştirir.
     *
     * İKİ ÖLÇÜM burada birleşiyor (vCenter 8.0.3):
     *
     *  - Gövde DÜZ yazılır. `{"config": {...}}` sarmalı 400 ile
     *    "Unexpected property with name: {'config'}" alıyor — sarmalı yalnızca
     *    hesap AÇARKEN (eski ad alanı) istiyor.
     *  - `old_password` HER ZAMAN gerekiyor. SDK açıklaması "superAdmin
     *    başkasının parolasını değiştirirken istenmez" diyor ama cihaz
     *    "Old password is required to reset the password." ile reddediyor.
     *    Belgeye güvenip göndermeseydik tur, parola değişmemişken
     *    değişmiş sanılacağı bir yerde düşerdi.
     */
    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $response = $this->client($session)->patch(self::ACCOUNTS.rawurlencode($username), [
            'password' => $newPassword,
            'old_password' => $this->currentPassword($session, $username),
        ]);

        $this->guardResponse($response, 'Parola değiştirilemedi');
    }

    /**
     * Hedef hesabın ŞU ANKİ parolası.
     *
     * Turda değişimi kurtarma hesabı yapar, yani oturumun parolası hedefin
     * parolası DEĞİLDİR; o zaman kasadaki kayıtlı değer kullanılır. Onarım
     * dalında ise hesap kendi parolasını değiştirir.
     */
    private function currentPassword(RotationSession $session, string $username): string
    {
        if ($session->username === $username) {
            return $session->password;
        }

        $credential = $this->credential($session);

        if ($credential->username === $username && filled($credential->password)) {
            return (string) $credential->password;
        }

        throw new RotationException(
            "'{$username}' hesabının mevcut parolası bilinmiyor; vCenter eski parolayı zorunlu tutuyor.",
        );
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);

        $response = $this->client($session)->delete(self::ACCOUNTS.rawurlencode($username));

        /*
         * Hesap ZATEN YOKSA iş bitmiştir.
         *
         * Kurtarma kimliği makineye gitmeden önce kayda geçiyor (böylece bir
         * aksilikte elimizde adı kalıyor); hesap açma o adımda düşerse
         * makinede öyle bir hesap hiç oluşmuyor. 404'ü hata saymak, silinecek
         * bir şey olmadığı hâlde kaydı sonsuza dek "yarıda kalmış" bırakırdı.
         */
        if ($response->status() === 404) {
            return;
        }

        $this->guardResponse($response, 'Kurtarma hesabı silinemedi');
    }

    /**
     * Kimlik GİRİŞ yapabiliyor mu.
     *
     * open() KULLANILMAZ: o, yönetim yetkisini de sınıyor ve yerel hesaplar
     * o yetkiye hiç sahip değil. Doğrulanan şey "yeni parola çalışıyor mu"
     * sorusudur; yerel hesabın yönetici olup olmaması bunu ilgilendirmez.
     * open() üzerinden sorulsaydı, parola doğru değişmişken tur
     * "doğrulanamadı" diye kapanırdı.
     */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        try {
            $response = Http::withBasicAuth($username, $password)
                ->withOptions(['verify' => false])
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($this->apiUrl($credential).'/api/session');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function closeSession(Credential $credential, string $token): void
    {
        try {
            Http::withHeaders(['vmware-api-session-id' => $token])
                ->withOptions(['verify' => false])
                ->timeout($this->timeout)
                ->delete($this->apiUrl($credential).'/api/session');
        } catch (\Throwable) {
            /* Oturumu kapatamamak turu bozmaz; sunucu kendiliğinden düşürür. */
        }
    }

    private function client(RotationSession $session): PendingRequest
    {
        $handle = $session->handle;

        return Http::withHeaders(['vmware-api-session-id' => $handle['token']])
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->baseUrl($this->apiUrl($handle['credential']));
    }

    private function credential(RotationSession $session): Credential
    {
        return $session->handle['credential'];
    }

    /**
     * vCenter hata gövdesinde nedeni anlatır; yalnızca duruma bakmak onu
     * kaybetmek olurdu.
     */
    private function guardResponse($response, string $failure): void
    {
        if ($response->successful()) {
            return;
        }

        $body = (array) $response->json();

        /*
         * İki ad alanı hatayı İKİ AYRI biçimde sarıyor: /api düz
         * {"messages": [...]}, /rest ise {"value": {"messages": [...]}}.
         * Yalnızca ilkine bakmak, hesap açma hatalarının nedenini
         * kaybettirirdi.
         */
        $detail = $body['messages'][0]['default_message']
            ?? $body['value']['messages'][0]['default_message']
            ?? $body['error_type']
            ?? $body['value']['error_type']
            ?? $response->status();

        throw new RotationException($failure.': '.$detail);
    }

    /**
     * Oturum açmak YETMEZ: yetki de sınanır.
     *
     * vCenter 8.0.3'te appliance'ın KENDİ root hesabı
     * /api/session'dan jeton alabiliyor ama yerel hesap uçlarında 403
     * "Unable to authorize user" yiyor; o uçları yalnızca SSO yöneticisi
     * kullanabiliyor.
     *
     * Bu sınama olmadan panel kaydı "hazır" gösteriyor, tur ise ilk gerçek
     * adımda anlaşılmaz bir 403 ile düşüyordu. Kimliğin yetersizliği en
     * başta ve İNSANCA söylenmeli — üstelik henüz hiçbir şey değişmemişken.
     */
    private function guardAuthorization(Credential $credential, string $token): void
    {
        $response = Http::withHeaders(['vmware-api-session-id' => $token])
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->timeout($this->timeout)
            ->get($this->apiUrl($credential).self::ACCOUNTS);

        if ($response->status() !== 403) {
            return;
        }

        throw new RotationException(
            'Bu kimlik appliance yerel hesaplarını yönetemiyor. '
            .'Güncelleme hesabına SSO yöneticisini yazın (ör. administrator@vsphere.local).',
        );
    }

    /**
     * REST API adresi.
     *
     * Kaydın portu yönetim arayüzünü (5480) gösterebilir; API oradan
     * konuşmuyor (bkz. config/rotation.php → vcenter.api_port).
     */
    private function apiUrl(Credential $credential): string
    {
        return DeviceAddress::port($credential, config('rotation.vcenter.api_port', 443));
    }

    private function guardName(string $username): void
    {
        if (preg_match('/^[a-z_][a-z0-9_-]{0,30}$/', $username) !== 1) {
            throw new RotationException('Hesap adı appliance için geçersiz karakter içeriyor.');
        }
    }
}
