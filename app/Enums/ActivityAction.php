<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kayda geçen işlem.
 *
 * Revealed ("parola görüntülendi") diğerlerinden farklıdır: hiçbir şeyi
 * değiştirmez ama denetimde en çok sorulan sorudur — bu yüzden kaydedilir.
 */
enum ActivityAction: string
{
    case Revealed = 'revealed';

    /*
     * Parola DIŞARI ÇIKARILDI (toplu çıktı).
     *
     * Görüntülemekten ayrı tutulur: görüntülenen parola ekranda kalır ve
     * kapanır; çıktıya alınan parola bir dosyaya yazılıp panelin dışına gider
     * ve oradan sonrası kaydın erişiminde değildir. Denetimde sorulan soru da
     * ayrıdır — "kim baktı" değil, "ne dışarı çıktı".
     */
    case Exported = 'exported';
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case PortalSession = 'portal_session';
    case ConsoleOpened = 'console_opened';
    case ConsoleClosed = 'console_closed';

    /*
     * Vekillenen cihaz arayüzünde YAPILAN İŞ.
     *
     * ESXi arayüzünün bütün çağrıları panelin vekilinden geçiyor; hangi
     * yönetim çağrısının atıldığı oradan okunabiliyor. "Kim girdi" kadar
     * "ne yaptı" da denetimin sorduğu sorudur.
     */
    case DeviceOperation = 'device_operation';
    case Rotated = 'rotated';
    case RotationFailed = 'rotation_failed';
    case LoggedIn = 'logged_in';
    case LoginFailed = 'login_failed';

    /* Denetim satırı silmek de bir işlemdir ve kaydı zincire yazılır. */
    case Pruned = 'pruned';

    /* Panel güncellemesi başlatıldı (platformun deploy hook'u tetiklendi). */
    case UpdateStarted = 'update_started';

    public function label(): string
    {
        return match ($this) {
            self::Revealed => 'Parola görüntülendi',
            self::Exported => 'Parola çıktıya alındı',
            self::Created => 'Oluşturuldu',
            self::Updated => 'Güncellendi',
            self::Deleted => 'Silindi',
            self::PortalSession => 'Portal oturumu açıldı',
            self::ConsoleOpened => 'Konsol açıldı',
            self::ConsoleClosed => 'Konsol kapatıldı',
            self::DeviceOperation => 'Cihazda işlem',
            self::Rotated => 'Parola güncellendi',
            self::RotationFailed => 'Parola güncellenemedi',
            self::LoggedIn => 'Giriş yapıldı',
            self::LoginFailed => 'Başarısız giriş',
            self::Pruned => 'Kayıt budandı',
            self::UpdateStarted => 'Güncelleme başlatıldı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Revealed => BadgeVariant::Yellow,
            self::Exported => BadgeVariant::Orange,
            self::Created => BadgeVariant::Green,
            self::Updated => BadgeVariant::Blue,
            self::Deleted, self::LoginFailed, self::RotationFailed, self::Pruned => BadgeVariant::Red,
            self::Rotated => BadgeVariant::Cyan,
            self::PortalSession, self::ConsoleOpened => BadgeVariant::Orange,
            self::DeviceOperation, self::UpdateStarted => BadgeVariant::Blue,
            self::ConsoleClosed => BadgeVariant::Gray,
            self::LoggedIn => BadgeVariant::Gray,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $action): array => [$action->value => $action->label()])
            ->all();
    }
}
