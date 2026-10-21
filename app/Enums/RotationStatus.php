<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir parola döndürme denemesinin sonucu.
 *
 * Failed ile Unverified AYRI durumlardır ve bu ayrım bu işin en kritik
 * yeridir: "değiştiremedim" ile "değiştirdim ama doğrulayamadım" farklı
 * şeylerdir. İkincisinde cihazdaki parola YENİ olabilir, kasadaki ESKİ —
 * ve o hâlde denenen parola mutlaka saklanmalıdır.
 */
enum RotationStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unverified = 'unverified';
    case RolledBack = 'rolled_back';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Sürüyor',
            self::Succeeded => 'Başarılı',
            self::Failed => 'Başarısız',
            self::Unverified => 'Doğrulanamadı',
            self::RolledBack => 'Geri alındı',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Running => BadgeVariant::Blue,
            self::Succeeded => BadgeVariant::Green,
            self::Failed => BadgeVariant::Gray,
            self::Unverified => BadgeVariant::Red,
            self::RolledBack => BadgeVariant::Yellow,
        };
    }

    /** Elle müdahale gerektiriyor mu? */
    public function needsAttention(): bool
    {
        return $this === self::Unverified;
    }
}
