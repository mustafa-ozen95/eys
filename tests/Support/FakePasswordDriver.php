<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\DeviceUnreachableException;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * Sahte makine.
 *
 * Canlı sisteme bağlanılmaz (bkz. .ai/rules/work.md); sıra, davranışı
 * programlanabilen bir makineye karşı sınanır. Gerçek sürücünün işi yalnızca
 * konuşmak; sıranın doğruluğu buradan kanıtlanır.
 *
 * Makinenin durumu $accounts dizisidir: hesap adı => parola. Testler buna
 * bakarak "makinede gerçekten ne var" sorusunu yanıtlar.
 */
class FakePasswordDriver implements PasswordDriver
{
    /** @var array<string, string> hesap => parola */
    public array $accounts;

    public bool $failCreateRecovery = false;

    /** Kurtarma hesabı açılır ama onunla bağlanılamaz. */
    public bool $recoveryUnusable = false;

    /** Son oturumu açan kimlik — hangi hesapla bağlanıldığını sınamak için. */
    public ?string $lastIdentity = null;

    public bool $failChangePassword = false;

    /** Parola makineye yazılır ama doğrulama başarısız olur. */
    public bool $failNewPasswordVerify = false;

    /**
     * Doğrulama sırasında cihaza ULAŞILAMAZ.
     *
     * "Cihaz yanıt vermedi" ile "cihaz parolayı kabul etmedi" aynı şey değil;
     * ikisini `false` altında birleştirmek kasayı cihazdan ayrıştırıyordu
     * (bkz. DeviceUnreachableException).
     */
    public bool $unreachableOnVerify = false;

    public bool $failDeleteRecovery = false;

    /**
     * Yalnızca BU kimlik kurtarma hesabını silebilir.
     *
     * Gerçeği taklit eder: giriş yapabilmek ile yetkili olmak aynı şey değil
     * (vCenter'da root giriş yapıyor ama hesap uçlarında 403 alıyor).
     */
    public ?string $yetkiliSilme = null;

    public int $openCount = 0;

    public int $closedCount = 0;

    /** @param  array<string, string>  $accounts */
    public function __construct(array $accounts = ['root' => 'eski-parola'])
    {
        $this->accounts = $accounts;
    }

    /** Sahte makine varsayılan olarak SSH gibi davranır; testler değiştirebilir. */
    public bool $supportsRecovery = true;

    public bool $canVerify = true;

    public function supportsRecovery(): bool
    {
        return $this->supportsRecovery;
    }

    public function canVerify(): bool
    {
        return $this->canVerify;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return blank($credential->host) ? 'Adres tanımlı değil.' : null;
    }

    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        if (! $this->canLogin($username, $password)) {
            throw new RotationException("Kimlik doğrulaması reddedildi ({$username}).");
        }

        $this->openCount++;
        $this->lastIdentity ??= $username;

        return new RotationSession('oturum', $username, $password, function (): void {
            $this->closedCount++;
        });
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        if ($this->failCreateRecovery) {
            throw new RotationException('Kurtarma hesabı açılamadı.');
        }

        $this->accounts[$username] = $password;
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        if ($this->failChangePassword) {
            throw new RotationException('Parola değiştirilemedi.');
        }

        $this->accounts[$username] = $newPassword;
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        if ($this->failDeleteRecovery) {
            throw new RotationException('Kurtarma hesabı silinemedi.');
        }

        /* Yetki sınaması: giriş yapabilmek silme yetkisi vermez. */
        if ($this->yetkiliSilme !== null && $session->username !== $this->yetkiliSilme) {
            throw new RotationException('Bu kimlik hesap silemez.');
        }

        unset($this->accounts[$username]);
    }

    public function verify(Credential $credential, string $username, string $password): bool
    {
        if ($this->unreachableOnVerify) {
            throw new DeviceUnreachableException('Cihaza ulaşılamadı (sınama).');
        }

        if ($this->recoveryUnusable && str_starts_with($username, 'eys-kurtarma-')) {
            return false;
        }

        if ($this->failNewPasswordVerify && $username === $credential->username
            && $password !== $credential->getRawOriginal('password')) {
            return false;
        }

        return $this->canLogin($username, $password);
    }

    /** Makinede kurtarma hesabı kaldı mı? */
    public function hasRecoveryAccount(): bool
    {
        foreach (array_keys($this->accounts) as $name) {
            if (str_starts_with($name, 'eys-kurtarma-')) {
                return true;
            }
        }

        return false;
    }

    private function canLogin(string $username, string $password): bool
    {
        return ($this->accounts[$username] ?? null) === $password;
    }
}
