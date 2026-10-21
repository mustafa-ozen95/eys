<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bir yetki satırı izin mi verir, yasak mı koyar?
 *
 * Yasak satırı olmasaydı "hepsinde görsün, Grup A hariç" yazılamaz, bunun yerine
 * Grup A dışındaki her grup tek tek işaretlenirdi — ve yeni bir grup açıldığında
 * kimse listeyi güncellemeyi hatırlamazdı.
 */
enum PermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'İzin',
            self::Deny => 'Yasak',
        };
    }
}
