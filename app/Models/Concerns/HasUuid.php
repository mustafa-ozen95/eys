<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Sıralı birincil anahtar dışarı ÇIKMAZ.
 *
 * Adres çubuğundaki 1, 2, 3 hem kaç kaydınız olduğunu söyler hem de komşu
 * kaydı denemeye davet eder. Rotalar uuid ile bağlanır; id yalnızca
 * veritabanının içinde kalır.
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
