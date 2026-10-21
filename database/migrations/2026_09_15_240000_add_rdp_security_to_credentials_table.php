<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAYIT BAZINDA RDP GÜVENLİK KİPİ.
 *
 * Tek bir genel ayar (config/remote.php · 'any') yetmez, çünkü makineler
 * aynı şeyi konuşmaz: kimi eski RDP / TLS / NLA'yı kabul eder ama
 * sertifikası RSA-SHA1 imzalıdır, kimi YALNIZCA NLA konuşur
 * (HYBRID_REQUIRED_BY_SERVER).
 *
 * SHA-1 imzalı sertifikayı OpenSSL 3 yalnızca güvenlik seviyesi 0'da kabul
 * eder; FreeRDP seviyeyi kendisi kurar ve Guacamole onu dışarı açmaz. Yani
 * böyle bir makineye TLS ile bağlanmanın panel tarafında yolu yoktur; ama
 * ESKİ RDP güvenliğinde TLS hiç devreye girmez — sertifika da girmez.
 *
 * TEK BİR GENEL AYAR BU İŞİ ÇÖZMEZ: "rdp" yazılırsa yalnızca NLA konuşan
 * makine kopar. Karar makine başına verilmeli.
 *
 * BOŞ = config'teki varsayılan ("any", pazarlığı FreeRDP'ye bırakır).
 * Bilinçli olarak yazılan bir değer o kaydı ona sabitler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->string('rdp_security', 10)->nullable()->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn('rdp_security');
        });
    }
};
