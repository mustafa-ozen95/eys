<?php

declare(strict_types=1);

namespace App\Services\Rotation\Contracts;

use App\Models\Credential;
use App\Services\Rotation\RotationSession;

/**
 * Bir platformda hesap açıp parola değiştirebilen sürücü.
 *
 * Sürücünün işi YALNIZCA makineyle konuşmaktır: sırayı, doğrulamayı, geri
 * almayı ve kasaya yazmayı PasswordRotator yönetir. Böylece yeni bir platform
 * eklemek güvenlik sırasını yeniden düşünmeyi gerektirmez.
 */
interface PasswordDriver
{
    /** Bu sürücü kaydı işleyebilir mi? Eksik alan varsa nedeni döner. */
    public function unsupportedReason(Credential $credential): ?string;

    /**
     * Bu platformda kurtarma hesabı açılabilir mi?
     *
     * SSH ve Windows'ta açılabilir: parolasını değiştirdiğimiz hesapla
     * bağlanıyoruz, o yüzden bağımsız bir kapıya ihtiyacımız var.
     *
     * API anahtarıyla çalışan servislerde (mailcow) GEREKMEZ: değiştiren
     * kimlik ile değiştirilen parola zaten ayrı şeyler; anahtar bozulmadığı
     * için kapı hep açık. Böyle bir yerde geçici hesap açmak, ortada olmayan
     * bir riske karşılık gerçek bir yetki bırakmak olurdu.
     */
    public function supportsRecovery(): bool;

    /**
     * Yeni parolanın gerçekten çalıştığı KANITLANABİLİYOR mu?
     *
     * Kanıtlanamıyorsa tur "başarılı" sayılmaz; "doğrulanamadı" olarak
     * kapanır ve gerekçesi yazılır. Doğrulayamadığımız bir değişikliğe
     * başarılı demek, kasadaki veriye duyulan güveni bozar.
     */
    public function canVerify(): bool;

    /** Verilen kimlikle oturum açar. Açılamazsa RotationException atar. */
    public function open(Credential $credential, string $username, string $password): RotationSession;

    /** Tam yetkili bir kurtarma hesabı açar. */
    public function createRecovery(RotationSession $session, string $username, string $password): void;

    /** Bir hesabın parolasını değiştirir. */
    public function changePassword(RotationSession $session, string $username, string $newPassword): void;

    /** Kurtarma hesabını siler. */
    public function deleteRecovery(RotationSession $session, string $username): void;

    /**
     * Verilen kimlikle GERÇEKTEN girilebiliyor mu.
     *
     * İstisna ATMAZ, false döner: rotator bu yanıta bakıp geri alma kararı
     * veriyor; istisna atsaydı kurtarma yolu hiç çalışmazdı.
     */
    public function verify(Credential $credential, string $username, string $password): bool;
}
