<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Grubun ne olduğu — listede grupları kabaca ayırmak için.
 *
 * Yetki taşımaz; yalnızca sıralama ve renk verir.
 */
enum GroupKind: string
{
    case Server = 'server';
    case Virtualization = 'virtualization';
    case Network = 'network';
    case Storage = 'storage';
    case Workstation = 'workstation';
    case Service = 'service';
    case Membership = 'membership';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Server => 'Sunucu',
            self::Virtualization => 'Sanallaştırma',
            self::Network => 'Ağ',
            self::Storage => 'Depolama',
            self::Workstation => 'İş istasyonu',
            self::Service => 'Servis',
            self::Membership => 'Üyelik ve lisans',
            self::Other => 'Diğer',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Server => BadgeVariant::Blue,
            self::Virtualization => BadgeVariant::Purple,
            self::Network => BadgeVariant::Green,
            self::Storage => BadgeVariant::Cyan,
            self::Workstation => BadgeVariant::Orange,
            self::Service => BadgeVariant::Yellow,
            self::Membership => BadgeVariant::Pink,
            self::Other => BadgeVariant::Gray,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }
}
