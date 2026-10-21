<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Passive = 'passive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Etkin',
            self::Passive => 'Pasif',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Active => BadgeVariant::Green,
            self::Passive => BadgeVariant::Gray,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
