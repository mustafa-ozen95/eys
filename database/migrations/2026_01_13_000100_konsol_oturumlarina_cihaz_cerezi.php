<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cihazda açılan oturumun çerezi, KAPATABİLMEK için satırda saklanır.
 *
 * Sunucu tarafında giriş yapılan arayüzlerde (Plesk, iLO, vSphere) oturum
 * CİHAZDA açılıyor ve orada kapanması gerekiyor. Çerez bugüne kadar yalnızca
 * önbellekte duruyordu; önbellekteki kayıt oturumun ömrü dolunca siliniyor ve
 * geriye kapatılamayan bir cihaz oturumu kalıyordu. Cihazın kendi oturum
 * listesi (ör. iLO'da) böyle dolup taşıyordu.
 *
 * ŞİFRELİ saklanır ve kapanışta NULL'lanır: kapanmış bir oturumun çerezi
 * elimizde durmamalı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_oturumlari', function (Blueprint $table): void {
            $table->text('cihaz_cerezi')->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_oturumlari', function (Blueprint $table): void {
            $table->dropColumn('cihaz_cerezi');
        });
    }
};
