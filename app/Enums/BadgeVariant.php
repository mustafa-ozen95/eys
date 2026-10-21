<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Arayüzdeki renk varyantları. CSS sınıfı dışında bir anlam taşımaz;
 * diğer enum'lar kendi durumlarını buraya eşler.
 */
enum BadgeVariant: string
{
    case Green = 'green';
    case Red = 'red';
    case Yellow = 'yellow';
    case Blue = 'blue';
    case Purple = 'purple';
    case Cyan = 'cyan';
    case Orange = 'orange';
    case Pink = 'pink';
    case Gray = 'gray';

    public function cssClass(): string
    {
        return 'badge-'.$this->value;
    }
}
