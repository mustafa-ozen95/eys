<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * KİŞİSEL ALAN KALDIRILDI. Taşıyıcı grup silinir — YALNIZCA BOŞSA. İçinde kayıt
 * kalmışsa göç dokunmaz: birinin sırrı sessizce yok olmamalı.
 *
 * `is_personal` ve `owner_id` sütunları yerinde kalır; okuma tarafındaki
 * korumalar (Credential::kisiselMi) sahipli kaydı yine kimseye açmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('credential_groups')
            ->where('is_personal', true)
            ->whereNotExists(fn ($kayit) => $kayit->from('credentials')
                ->whereColumn('credentials.credential_group_id', 'credential_groups.id'))
            ->delete();
    }

    public function down(): void
    {
        //
    }
};
