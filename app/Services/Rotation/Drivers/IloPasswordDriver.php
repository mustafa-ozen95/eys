<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\DeviceAddress;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HPE iLO — nesli kendisi bulur, iLO 3'ten 6'ya kadar.
 *
 * NEDEN VAR: Redfish iLO 3'te YOKTUR, iLO 4'te ancak firmware 2.30 ve
 * sonrasında gelir; RIBCL dördünde de çalışır ama yeni nesillerde HPE
 * Redfish'i öneriyor. Bu sürücü olmadan, her iLO kaydında
 * "bu hangi nesil" sorusunun elle yanıtlanması gerekirdi — ve yanlış yanıt
 * ancak ilk döndürme denemesinde ortaya çıkardı.
 *
 * ALGILAMA: /redfish/v1/ uçuna KİMLİKSİZ bir istek atılır. Redfish servis
 * kökü herkese açıktır (kimlik yalnızca alt kaynaklarda gerekir), bu yüzden
 * yoklama parola göndermeden yapılabilir. JSON dönerse Redfish, dönmezse
 * RIBCL.
 *
 * Sonuç makine başına önbelleklenir: her çağrıda yeniden yoklamak iLO'lara
 * gereksiz trafik demek. Firmware yükseltilirse önbellek süresi
 * dolduğunda kendiliğinden düzelir.
 */
class IloPasswordDriver implements PasswordDriver
{
    public const KEY = 'ilo';

    private const CACHE_TTL_HOURS = 24;

    public function __construct(
        private RedfishPasswordDriver $redfish,
        private RibclPasswordDriver $ribcl,
        private int $probeTimeout = 8,
    ) {}

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
        return $this->driverFor($credential)->open($credential, $username, $password);
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        $this->driverForSession($session)->createRecovery($session, $username, $password);
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        $this->driverForSession($session)->changePassword($session, $username, $newPassword);
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->driverForSession($session)->deleteRecovery($session, $username);
    }

    public function verify(Credential $credential, string $username, string $password): bool
    {
        return $this->driverFor($credential)->verify($credential, $username, $password);
    }

    /** Hangi yolun seçildiği — ekranda ve kayıtta gösterilebilsin diye. */
    public function protocolFor(Credential $credential): string
    {
        return $this->supportsRedfish($credential) ? RedfishPasswordDriver::KEY : RibclPasswordDriver::KEY;
    }

    /**
     * Oturumdan sürücüye geri dönüş.
     *
     * İki alt sürücü de oturumun içine kaydın kendisini koyuyor; aynı kayıt
     * için aynı yol yeniden çözülür. Oturuma sürücüyü de koymak yerine
     * yeniden çözmek, önbellek sayesinde bedava ve iki alt sürücünün
     * oturum biçimini değiştirmemizi gerektirmiyor.
     */
    private function driverForSession(RotationSession $session): PasswordDriver
    {
        $credential = $session->handle;

        if (! $credential instanceof Credential) {
            throw new RotationException('iLO oturumu tanınmadı.');
        }

        return $this->driverFor($credential);
    }

    private function driverFor(Credential $credential): PasswordDriver
    {
        return $this->supportsRedfish($credential) ? $this->redfish : $this->ribcl;
    }

    private function supportsRedfish(Credential $credential): bool
    {
        $key = 'ilo:redfish:'.md5(DeviceAddress::for($credential));

        return (bool) Cache::remember($key, now()->addHours(self::CACHE_TTL_HOURS), function () use ($credential): bool {
            try {
                $response = Http::withOptions(['verify' => false])
                    ->acceptJson()
                    ->timeout($this->probeTimeout)
                    ->get(DeviceAddress::for($credential).'/redfish/v1/');
            } catch (Throwable) {
                /*
                 * Yoklama başarısızsa RIBCL'e düşülür. Yanlış tahmin güvenli:
                 * RIBCL iLO 5/6'da da çalışır, yalnızca eski yoldur.
                 */
                return false;
            }

            return $response->successful() && is_array($response->json());
        });
    }
}
